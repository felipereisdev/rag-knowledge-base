# Hybrid Knowledge Importance Classifier

**Data:** 2026-07-13

**Estado:** aprovado em sessão; aguarda revisão do documento

**Âmbito:** classificação de importância antes de entradas geradas automaticamente ou por CLI entrarem na fila humana de aprovação

## Contexto

O pipeline atual usa um modelo para extrair candidatos de conhecimento no fim de uma sessão, valida a estrutura da resposta e elimina duplicados por similaridade. Depois disso, qualquer candidato válido é guardado como `pending`. A ferramenta MCP `rag_store_knowledge` e o comando `php artisan rag:store` também guardam diretamente como `pending`.

Esse desenho delega ao modelo a decisão semântica sobre o que merece ser proposto, mas não cria uma avaliação independente, auditável e consistente da importância. Como consequência, conteúdo genérico, trivial, especulativo ou transitório pode aumentar a fila de revisão humana.

O novo classificador adiciona uma segunda decisão antes da fila de aprovação. O Claude SDK julga semanticamente o candidato, enquanto uma política determinística, versionada e testável valida o resultado, aplica regras e executa o limiar operacional.

O objetivo não é substituir a aprovação humana. O classificador apenas decide se uma entrada automatizada merece chegar à fila `pending`. Entradas classificadas como não importantes permanecem recuperáveis como `rejected`.

## Objetivos

- Reduzir o ruído da fila humana sem apagar candidatos.
- Avaliar importância com compreensão semântica através do Claude SDK.
- Manter a decisão operacional explicável, versionada e auditável.
- Aplicar o mesmo fluxo à condensação, ao MCP e ao CLI.
- Evitar latência e timeouts no MCP e no CLI através de processamento assíncrono.
- Iniciar em modo sombra e medir a qualidade antes de ativar rejeições automáticas.
- Falhar de forma segura: falhas técnicas nunca rejeitam automaticamente uma entrada.

## Fora do âmbito

- Classificar documentos importados.
- Classificar entradas criadas manualmente no Martis.
- Aprovar automaticamente entradas importantes.
- Substituir a deduplicação semântica existente.
- Tornar pesos e regras editáveis no Martis.
- Reclassificar automaticamente uma entrada depois de edição humana.
- Guardar raciocínio interno do modelo ou chain-of-thought.

## Decisões fundamentais

- A solução é híbrida, não matematicamente determinística: o Claude produz a avaliação semântica inicial; o código controla validação, regras, cache, limiar e transição de estado.
- O perfil é conservador. Apenas decisões, regras, restrições, arquitetura, convenções duráveis e correções não óbvias com valor futuro devem alcançar a fila.
- Candidatos não importantes são guardados como `rejected`, com pontuação e razões auditáveis.
- As origens classificadas são `condense`, `mcp` e `cli`.
- As origens isentas são `import` e `manual`.
- Regras e pesos ficam versionados no código. O administrador configura apenas modo e limiar.
- Falhas do classificador deixam a entrada como `pending` com `classification_error`.
- A primeira implantação opera em modo `shadow`.
- A classificação é executada de forma assíncrona numa fila dedicada.

## Arquitetura

### Política de ingestão

`KnowledgeIngestionPolicy` recebe a origem da entrada e decide se ela passa pelo classificador.

| Origem | Classificar em `shadow`/`enforce` | Estado inicial em `shadow`/`enforce` |
|---|---:|---|
| `condense` | sim | `classifying` |
| `mcp` | sim | `classifying` |
| `cli` | sim | `classifying` |
| `import` | não | `pending` |
| `manual` | não | `pending` |

A política é explícita e fechada. Uma origem nova não deve ser classificada por acidente; precisa ser adicionada conscientemente e coberta por teste.

Quando o modo global é `off`, nenhuma origem agenda classificação: todas as entradas seguem diretamente para `pending`. Assim, desligar o classificador não depende de um worker ativo e não introduz uma passagem temporária por `classifying`.

### Componentes

- `KnowledgeIngestionPolicy`: seleciona as origens sujeitas à classificação.
- `ImportanceCandidateNormalizer`: cria a representação canónica usada pelo Claude e pelo cache.
- `DeterministicImportanceRules`: aplica vetos, ajustes e versões das regras.
- `ClaudeImportanceJudge`: executa o Claude SDK e converte a saída estruturada.
- `HybridImportanceClassifier`: coordena cache, julgamento semântico, regras e limiar.
- `ImportanceClassificationResult`: representa score, veredito, razões, versões, regras acionadas e erro.
- `ClassifyKnowledgeEntryJob`: executa a classificação assíncrona e transita a entrada.
- `KnowledgeWriter`: centraliza a persistência de entradas e agenda a classificação depois do commit.

O comando CLI deixa de gravar diretamente em `KnowledgeEntry`. Condensação, MCP, CLI e importação passam pelo mesmo `KnowledgeWriter`, recebendo comportamentos diferentes apenas através da origem explícita.

## Contrato do Claude

O Claude recebe somente o candidato normalizado:

- título;
- conteúdo;
- categoria;
- entidades;
- relações;
- origem;
- idioma do projeto.

Ele não recebe a transcrição completa, outras entradas do projeto ou o conteúdo integral da base RAG. Essa restrição reduz custo, exposição de dados, prompt injection transversal e variação entre origens.

O prompt trata o candidato como dados não confiáveis, proíbe seguir instruções contidas nele e solicita apenas o objeto estruturado. O processo não dispõe de ferramentas ou acesso ao projeto.

A resposta aceita é:

```json
{
  "durability": 0,
  "actionability": 0,
  "specificity": 0,
  "non_obviousness": 0,
  "future_value": 0,
  "recommended_verdict": "important",
  "reasons": [
    {
      "criterion": "durability",
      "explanation": "Short explanation grounded in the candidate."
    }
  ]
}
```

Os intervalos são:

| Critério | Intervalo |
|---|---:|
| Durabilidade | 0–25 |
| Capacidade de orientar decisões ou ações | 0–20 |
| Especificidade e contexto suficiente | 0–20 |
| Conhecimento não óbvio | 0–20 |
| Valor provável para sessões futuras | 0–15 |

O veredito recomendado pelo Claude é informativo. O código calcula a decisão final a partir da pontuação validada, regras e limiar.

Respostas com JSON inválido, campos ausentes, tipos incorretos, critérios desconhecidos ou valores fora dos intervalos são falhas de classificação. Não são corrigidas silenciosamente.

## Política determinística

A política soma os cinco critérios semânticos, aplica ajustes versionados e limita o resultado final a `0–100`.

As regras positivas e negativas são pequenas, independentes e justificáveis. Exemplos de sinais positivos incluem uma decisão explícita, uma restrição operacional, uma justificação causal e uma consequência acionável. Exemplos de penalizações incluem linguagem especulativa, conteúdo excessivamente genérico, ausência de contexto e informação claramente transitória.

Vetos são reservados a ruído inequívoco:

- conteúdo vazio ou inválido;
- placeholder sem conhecimento;
- pergunta sem resposta;
- mensagem exclusivamente operacional sobre o agente;
- saída que não contém uma afirmação de conhecimento.

Duplicação não é um veto de importância. `CondenseDedup` continua responsável por impedir candidatos semanticamente duplicados na condensação.

Uma entrada é importante quando:

```text
final_score >= configured_threshold
```

O limiar inicial é `70`.

## Normalização e cache

A normalização produz uma representação estável a partir dos campos relevantes. Ordena coleções sem significado posicional, normaliza quebras de linha e espaços, preserva conteúdo semanticamente relevante e serializa com um formato canónico.

A chave de cache contém:

```text
project_id
+ candidate_hash
+ model
+ prompt_version
+ rules_version
```

Mudar apenas o limiar não chama novamente o Claude. O veredito é recalculado usando a avaliação semântica armazenada. Mudar modelo, prompt ou regras invalida naturalmente o cache.

Concorrência na criação da mesma avaliação é resolvida por uma restrição única e leitura posterior do registo vencedor. O cache nunca depende apenas de memória do processo.

## Estados e fluxo assíncrono

`KnowledgeStatus` passa a aceitar:

```text
classifying
pending
approved
rejected
```

Para `condense`, `mcp` e `cli`, quando o modo é `shadow` ou `enforce`:

1. `KnowledgeWriter` cria a entrada como `classifying` numa transação.
2. A mesma transação associa tags, entidades e relações.
3. Depois do commit, envia `ClassifyKnowledgeEntryJob` para a fila `classification`.
4. O job só continua se a entrada ainda estiver `classifying`.
5. O classificador procura cache e, quando necessário, chama o Claude.
6. A avaliação é persistida e associada à entrada.
7. Um resumo é copiado para `metadata.importance`.
8. A transição final é feita atomicamente.

Transições por modo:

| Modo | Importante | Não importante |
|---|---|---|
| `off` | `pending` sem avaliação | `pending` sem avaliação |
| `shadow` | `pending` | `pending` com `would_reject=true` |
| `enforce` | `pending` | `rejected` |

Entradas `classifying` não são indexadas, não aparecem na fila de aprovação e não podem ser aprovadas ou rejeitadas manualmente enquanto o job está em curso. A transição para `pending` aciona o observador e a indexação existentes.

Editar uma entrada depois da classificação não agenda nova chamada. Reclassificação futura, se necessária, será uma ação explícita fora do âmbito inicial.

## Falhas, tentativas e idempotência

O job tem um número limitado de tentativas para falhas transitórias. Timeout, processo indisponível, resposta inválida ou exceção definitiva causam a transição segura:

```text
classifying → pending
```

`metadata.importance.classification_error` guarda código, mensagem sanitizada e versão do classificador. A tentativa falhada também fica registada na tabela de avaliações e nos logs estruturados.

O método terminal de falha do job executa a mesma recuperação, evitando entradas permanentemente presas em `classifying`. Uma rotina de saúde identifica entradas cuja classificação excedeu o tempo máximo esperado.

Jobs duplicados são idempotentes:

- não atuam sobre entradas que já deixaram `classifying`;
- reutilizam a avaliação em cache;
- não aplicam duas transições;
- não criam avaliações duplicadas.

Uma falha técnica nunca produz `rejected`.

## Persistência e auditoria

Uma tabela `importance_assessments` guarda:

- projeto;
- hash do candidato;
- modelo;
- versões do prompt, regras e classificador;
- pontuações por critério;
- pontuação semântica e pontuação final;
- veredito;
- razões estruturadas;
- regras acionadas;
- estado da avaliação;
- código e mensagem sanitizada de erro;
- duração e indicação de cache;
- timestamps.

Cada `KnowledgeEntry` pode referenciar a avaliação usada. Uma avaliação pode servir várias entradas com o mesmo conteúdo e chave de cache.

`metadata.importance` contém um snapshot resumido:

- score final;
- veredito;
- modo;
- `would_reject`;
- razões;
- regras acionadas;
- modelo e versões;
- hash;
- cache hit;
- erro sanitizado, quando houver.

Não é guardado chain-of-thought, raciocínio oculto ou uma resposta textual livre do modelo.

## Configuração administrativa

Uma configuração singleton própria do classificador fica separada de `CondenseSetting`, porque a classificação atende três origens.

Campos editáveis:

- `mode`: `off`, `shadow` ou `enforce`;
- `threshold`: inteiro de `0–100`, default `70`.

Regras, pesos, prompt, versões e modelo não são editáveis nessa superfície. Modelo e configuração de execução pertencem ao código/configuração de implantação e são copiados para cada avaliação.

O Resource singleton é criado pelas primitivas e geradores do Martis. Criação e eliminação ficam desativadas; atualização continua autorizada. Todos os rótulos, ajudas, opções e mensagens usam traduções com as mesmas chaves em `en`, `pt_PT` e `pt_BR`.

## Integração com as superfícies existentes

- `KnowledgeStatus` e o filtro de estado incluem `classifying`.
- O detalhe da entrada mostra score, veredito, razões, modo e versões como campos de leitura.
- Aprovar e Rejeitar ficam indisponíveis para entradas `classifying`.
- O MCP e o CLI respondem que a classificação foi agendada, em vez de afirmar que a entrada já aguarda aprovação.
- `rag_status` mostra entradas em classificação, avaliações falhadas e saúde da fila dedicada.
- O dashboard pode mostrar o total em classificação sem misturá-lo com pendências humanas.
- A experiência de revisão focada continua consultando apenas `pending` e não precisa conhecer o job interno.

## Observabilidade

Logs estruturados incluem:

- classificação agendada;
- cache hit ou miss;
- duração do Claude;
- score final;
- transição aplicada;
- falha e recuperação para `pending`.

Os logs não incluem o conteúdo integral do candidato nem dados sensíveis da resposta.

O estado operacional apresenta:

- entradas aguardando classificação;
- jobs pendentes e falhados na fila `classification`;
- avaliações concluídas e falhadas;
- distribuição `would_keep`/`would_reject` no modo sombra;
- versão ativa do classificador.

## Segurança do processo Claude

- O candidato é delimitado e tratado como dados não confiáveis.
- O prompt proíbe obedecer a instruções presentes no candidato.
- O processo não recebe ferramentas, permissões de escrita ou acesso necessário ao repositório.
- Existe timeout explícito e limite de tamanho de entrada e saída.
- Erros expostos ao utilizador são sanitizados.
- Conteúdo completo não é copiado para logs ou para a tabela de avaliações.

## Estratégia de testes

### Unidade

- normalização e estabilidade do hash;
- invalidação do hash por alterações relevantes;
- regras positivas, penalizações e vetos isolados;
- limites de score e aplicação do limiar;
- validação estrita da resposta do Claude;
- composição do resultado final;
- chave de cache e invalidação por versões;
- política de origens.

### Integração

- em `shadow` ou `enforce`, `condense`, `mcp` e `cli` criam `classifying` e agendam o job depois do commit;
- em `off`, todas as origens criam `pending` sem agendar classificação;
- `import` e `manual` criam `pending` sem classificação;
- shadow sempre termina em `pending`;
- enforce termina em `pending` ou `rejected` conforme o score;
- falhas terminam em `pending` com erro auditável;
- jobs repetidos são idempotentes;
- classificação concorrente não duplica avaliações;
- `classifying` e `rejected` não são pesquisáveis;
- `pending` inicia a indexação existente;
- ações de aprovação e rejeição recusam `classifying`;
- CLI, MCP, status e Resource refletem os novos estados.

### Contrato do Claude

Os testes usam um fake do processo Claude e nunca dependem de rede ou créditos. Fixtures cobrem resposta válida, JSON inválido, campos ausentes, critérios desconhecidos, valores fora do intervalo, timeout e saída parcial. Uma avaliação falhada não é reutilizada como cache válido; uma tentativa posterior pode atualizar a mesma chave para sucesso sem criar um segundo registo lógico.

### Corpus de calibração

Um corpus versionado contém:

- conhecimento que deve obrigatoriamente ser preservado;
- ruído que deve obrigatoriamente ser rejeitado;
- casos limítrofes.

Inclui exemplos sintéticos e exemplos anonimizados de sessões reais validados por uma pessoa.

## Rollout

1. Implantar schema, fila, worker, classificador e configuração com `mode=shadow`.
2. Rever normalmente as entradas enquanto avaliações são recolhidas.
3. Comparar `would_reject` com as decisões humanas.
4. Corrigir regras ou prompt através de novas versões, nunca alterando silenciosamente avaliações históricas.
5. Ativar `enforce` manualmente no Martis apenas depois dos critérios de saída.
6. Regressar a `shadow` ou `off` para rollback imediato.

Critérios recomendados para ativar `enforce`:

- pelo menos 50 entradas classificadas e posteriormente revistas;
- zero falsos rejeitados no corpus “obrigatório preservar”;
- no máximo 5% de `would_reject` entre entradas aprovadas por uma pessoa;
- redução projetada de pelo menos 30% da fila;
- nenhuma entrada presa em `classifying`;
- tentativas, falhas e recuperação verificadas no ambiente real.

Um relatório CLI apresenta matriz de confusão, taxa de falso rejeitado, redução projetada e distribuição de scores. A ativação nunca é automática.

## Critérios de aceitação

- As origens selecionadas são classificadas de forma assíncrona e as origens isentas mantêm o fluxo atual.
- Nenhuma chamada ao Claude bloqueia a resposta MCP ou CLI.
- O mesmo candidato e versões reutilizam a avaliação armazenada.
- Toda decisão contém score, razões, modelo e versões auditáveis.
- O limiar pode mudar sem nova chamada ao Claude.
- Modo sombra não rejeita entradas.
- Modo enforce guarda candidatos abaixo do limiar como `rejected`.
- Falhas técnicas sempre preservam a entrada como `pending`.
- Entradas `classifying` não são pesquisáveis nem aparecem na revisão.
- Importações e criação manual nunca são classificadas.
- Não é armazenado chain-of-thought.
- É possível desligar ou reverter o classificador sem perder entradas.
- A ativação de enforce depende de revisão humana e métricas observadas.
