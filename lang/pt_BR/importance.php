<?php

return [
    'resource' => [
        'label' => 'Classificador de Importância',
        'singular_label' => 'Classificador de Importância',
        'subtitle' => 'Como o conhecimento capturado é avaliado antes de chegar à fila de aprovação.',
    ],
    'fields' => [
        'mode' => 'Modo',
        'mode_help' => 'Desligado: captura tudo sem avaliar. Sombra: avalia e registra, mas nunca rejeita. Aplicar: rejeita entradas avaliadas como não importantes.',
        'threshold' => 'Limiar',
        'threshold_help' => 'Pontuação de 0 a 100 que uma entrada precisa atingir para ser considerada importante.',
        'active_model' => 'Modelo ativo',
        'active_model_help' => 'Definido na configuração, não aqui.',
        'prompt_version' => 'Versão do prompt',
        'prompt_version_help' => 'Definida na configuração, não aqui.',
        'rules_version' => 'Versão das regras',
        'rules_version_help' => 'Definida na configuração, não aqui.',
    ],
    'modes' => [
        'off' => 'Desligado',
        'shadow' => 'Sombra',
        'enforce' => 'Aplicar',
    ],
    'verdicts' => [
        'important' => 'Importante',
        'not_important' => 'Não importante',
    ],
    'audit' => [
        'section' => 'Importância',
        'score' => 'Pontuação de importância',
        'verdict' => 'Veredito',
        'mode' => 'Modo na classificação',
        'reasons' => 'Motivos',
        'rules' => 'Regras acionadas',
        'model' => 'Modelo',
        'prompt_version' => 'Versão do prompt',
        'rules_version' => 'Versão das regras',
        'cache' => 'Cache',
        'cache_hit' => 'Reutilizou uma avaliação anterior',
        'cache_miss' => 'Classificada novamente',
        'error' => 'Erro de classificação',
        'reason_line' => ':criterion: :explanation',
        'rule_line' => ':id (:adjustment): :reason',
        'error_line' => ':code: :message',
    ],
    'actions' => [
        'approve_blocked' => '{1} Essa entrada ainda está sendo classificada e ainda não pode ser aprovada.|[2,*] :count entradas ainda estão sendo classificadas e ainda não podem ser aprovadas.',
        'approve_partial' => '{1} Aprovadas :approved. Uma entrada ainda está sendo classificada e foi ignorada.|[2,*] Aprovadas :approved. :count entradas ainda estão sendo classificadas e foram ignoradas.',
        'reject_blocked' => '{1} Essa entrada ainda está sendo classificada e ainda não pode ser rejeitada.|[2,*] :count entradas ainda estão sendo classificadas e ainda não podem ser rejeitadas.',
        'reject_partial' => '{1} Rejeitadas :rejected. Uma entrada ainda está sendo classificada e foi ignorada.|[2,*] Rejeitadas :rejected. :count entradas ainda estão sendo classificadas e foram ignoradas.',
    ],
    'dashboard' => [
        'classifying' => 'Em Classificação',
    ],
];
