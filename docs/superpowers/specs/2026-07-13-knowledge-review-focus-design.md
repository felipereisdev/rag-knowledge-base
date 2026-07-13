# Knowledge Review Focus Mode

**Data:** 2026-07-13

**Estado:** aprovado em sessão; aguarda revisão do documento

**Âmbito:** experiência de aprovação e rejeição de entradas de conhecimento pendentes

## Contexto

A interface atual trata as entradas de conhecimento como um CRUD genérico. A tabela permite filtrar, selecionar em lote e executar ações inline, mas o título fica comprimido, o conteúdo completo só aparece num drawer e as ações de aprovação e rejeição não estão disponíveis junto ao conteúdo no detalhe. O dashboard mostra o número de pendentes, mas não transforma esse número numa tarefa clara.

O fluxo prioritário do produto é a curadoria: ler uma entrada, corrigir pequenos problemas, decidir e avançar para a próxima sem perder concentração.

## Objetivos

- Tornar a revisão individual rápida e confiável.
- Permitir corrigir título, conteúdo, categoria e tags sem sair da fila.
- Manter as ações em lote existentes para casos óbvios.
- Preservar validações, autorização, auditoria, tema, preferências e traduções do Martis.
- Tornar o fluxo eficiente com rato, teclado e ecrãs pequenos.

## Fora do âmbito

- Substituir o CRUD completo de entradas.
- Redesenhar pesquisa, grafo, projetos, entidades ou relações nesta fase.
- Editar projeto, origem, autor ou metadata no modo de foco.
- Aprovar automaticamente com base em confiança ou regras heurísticas.

## Direção escolhida

O fluxo usa um **cartão sequencial em modo de foco**, começando em **leitura**. Uma entrada ocupa a área principal do ecrã. A edição abre no mesmo lugar apenas quando solicitada, evitando ruído e alterações acidentais.

A tabela existente continua a ser a superfície para pesquisa, filtros, seleção e ações em lote. O modo de foco é a superfície principal para decisões cuidadosas.

## Entrada no fluxo

- O dashboard apresenta o total de pendentes num Card acionável e abre a revisão.
- A navegação inclui **Revisão**, com um badge que mostra o número de pendentes.
- **Todas as entradas** continua a abrir o índice normal do Resource.
- Ao entrar, a fila começa pela entrada pendente mais antiga.

## Ecrã de leitura

O cabeçalho mostra:

- posição atual, por exemplo `1 de 7`;
- projeto;
- categoria;
- origem e data;
- indicação curta dos atalhos disponíveis.

O corpo mostra o título, o conteúdo Markdown renderizado e as tags. As ações permanecem fixas no rodapé:

- **Rejeitar** (`R`);
- **Saltar** (`S`), sem alterar o estado;
- **Editar** (`E`);
- **Aprovar e avançar** (`A`).

`J` e `K` navegam pela fila. Aprovar ou rejeitar remove a entrada da fila e avança automaticamente. A próxima entrada é pré-carregada para evitar uma transição vazia.

## Ecrã de edição

Editar troca o conteúdo pelos campos editáveis no mesmo espaço, preservando a posição visual:

- título;
- conteúdo;
- categoria;
- tags.

As regras de validação são as mesmas do `KnowledgeEntryResource`. Erros aparecem junto ao respetivo campo. Guardar regressa ao modo de leitura; cancelar repõe os valores recebidos do servidor. Sair ou navegar com alterações por guardar pede confirmação.

## Decisões, avanço e desfazer

Aprovar e rejeitar não abrem um modal de confirmação. Depois de o servidor confirmar a operação, a interface avança e apresenta uma notificação com **Desfazer** durante 10 segundos.

Desfazer executa uma operação compensatória autorizada e auditada que devolve a entrada ao estado `pending`. Não se limita a alterar o estado apenas no cliente.

Enquanto uma edição ou decisão está em curso, os botões e atalhos de mutação ficam bloqueados para impedir pedidos duplicados. Navegação e avanço só ocorrem depois de uma resposta bem-sucedida.

## Integração com Martis

O fluxo será implementado como uma Lens de `KnowledgeEntryResource`, gerada com `php artisan martis:lens`. Uma Lens é a superfície adequada porque a experiência continua a representar uma consulta sobre o mesmo modelo e deve reutilizar filtros, ações, autorização e endpoints do Resource.

A Lens:

- consulta apenas entradas com estado `pending`;
- ordena por `created_at` ascendente e usa `id` como desempate;
- expõe apenas os campos necessários ao modo de foco;
- usa um componente React próprio através de `componentKey()`;
- reutiliza `ApproveEntries` e `RejectEntries` para as decisões;
- usa a atualização normal do Resource para as correções;
- acrescenta uma operação compensatória para Desfazer.

O componente é registado no bundle de extensões do consumidor e renderizado dentro do shell Martis. Não será criado um Tool separado, porque a página é uma vista do Resource e não uma superfície independente do modelo.

No menu, o item **Revisão** é construído como o item do `KnowledgeEntryResource`, com o caminho substituído pelo URL da Lens. Assim, reutiliza o badge numérico nativo e `menuCount()` pode devolver apenas o total pendente. **Todas as entradas** é um link separado para o índice normal, sem um segundo badge ambíguo.

No dashboard, o `ValueMetric` de pendentes é substituído por um Card gerado com `php artisan martis:card`. O Card mostra o total e um CTA para a Lens; a configuração inicial necessária ao componente segue em `meta()`.

## Estado e fluxo de dados

1. A Lens carrega uma página de entradas pendentes e seleciona a primeira.
2. O cliente pré-carrega a entrada seguinte, sem descarregar toda a fila.
3. Saltar muda apenas o cursor local.
4. Guardar envia a atualização, apresenta validações e substitui o estado local pela resposta do servidor.
5. Aprovar ou rejeitar executa a Action existente para a entrada atual.
6. Após sucesso, o cliente remove a entrada da coleção, avança e invalida fila, badge de navegação e métricas do dashboard.
7. Desfazer restaura `pending`, volta a inserir a entrada na posição correta e invalida os mesmos dados.

O URL mantém a identidade da entrada atual para permitir recarregar e partilhar a revisão sem depender apenas de estado em memória. Se a entrada já não estiver pendente, a Lens apresenta a próxima disponível.

## Estados de erro e estados vazios

- Falha ao carregar: mensagem clara, ação **Tentar novamente** e acesso a **Todas as entradas**.
- Falha ao guardar: mantém o modo de edição e os valores introduzidos.
- Falha ao aprovar, rejeitar ou desfazer: mantém a entrada atual e não avança.
- Se o servidor indicar que a entrada já não está pendente, a interface recarrega o estado e explica a alteração antes de avançar.
- Fila concluída: mostra **Tudo revisto**, o total tratado na sessão e ligações para todas as entradas e para o dashboard.
- Projeto sem pendentes: usa o mesmo estado vazio sem apresentar controlos inativos.

## Acessibilidade e responsividade

- Todos os controlos têm nome acessível, texto e ícone; nenhuma decisão depende apenas da cor.
- O foco passa para o título da nova entrada após avançar e regressa ao campo correto depois de um erro.
- Alterações de estado são anunciadas por uma região `aria-live`.
- Os atalhos não são ativados enquanto o utilizador escreve num campo e estão visíveis numa ajuda curta.
- A preferência de movimento reduzido do Martis elimina transições não essenciais.
- Em ecrãs pequenos, o conteúdo usa uma coluna e as ações permanecem fixas no rodapé.
- O conteúdo mantém uma largura de leitura confortável em ecrãs grandes.

## Internacionalização

Todo o texto novo usa traduções Laravel/React com chaves idênticas em:

- `lang/en/`;
- `lang/pt_PT/`;
- `lang/pt_BR/`.

Atalhos, mensagens de erro, estado vazio, notificações e nomes de navegação também são traduzidos. Não ficam strings de interface escritas diretamente nas classes ou componentes.

## Testes

### Backend

- a Lens devolve apenas entradas pendentes;
- a ordenação é determinística;
- autorização e filtros do Resource são respeitados;
- aprovar e rejeitar retiram a entrada da fila;
- desfazer restaura `pending` e fica auditado;
- atualizar reutiliza as regras do Resource;
- contadores de dashboard e navegação refletem as decisões.

### Componente

- começa em leitura e entra/sai de edição sem perder posição;
- atalhos executam apenas a ação correta e não atuam dentro de campos;
- pedidos concorrentes ficam bloqueados;
- avanço, saltar, desfazer e conclusão da fila atualizam o estado certo;
- falhas preservam conteúdo e foco;
- textos existem nas três localidades.

### Fluxo no navegador

- revisão completa com rato e teclado;
- layout em desktop e ecrã pequeno;
- mudança de tema, densidade, idioma e movimento reduzido;
- recuperação após recarregar um URL de entrada já processada.

## Critérios de aceitação

- É possível abrir a revisão a partir do dashboard e da navegação.
- Uma entrada pode ser lida, editada, aprovada ou rejeitada sem abandonar o modo de foco.
- Depois de uma decisão bem-sucedida, a próxima entrada aparece automaticamente.
- A decisão pode ser desfeita durante 10 segundos.
- A tabela e as ações em lote existentes continuam disponíveis.
- Erros não avançam a fila nem perdem alterações.
- O fluxo funciona com teclado, leitor de ecrã, tema claro/escuro e nas três localidades suportadas.
