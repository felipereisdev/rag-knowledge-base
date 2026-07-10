<?php

return [
    'fields' => [
        'enabled' => 'Ativado',
        'enabled_help' => 'Extrair conhecimento das sessões ao finalizar (Stop).',
        'driver' => 'Driver de extração',
        'driver_help' => 'Claude SDK reaproveita a sua assinatura Claude; API usa a chave de um provider.',
        'provider' => 'Provider de API',
        'provider_help' => 'Usado apenas quando o driver é "Provider de API".',
        'model' => 'Modelo',
        'model_help' => 'ex.: claude-haiku-4-5-20251001',
        'min_dedup_score' => 'Limite de deduplicação',
        'min_dedup_score_help' => 'Ignora um candidato quando uma entrada salva é pelo menos assim tão parecida (0-1).',
        'max_transcript_chars' => 'Máx. de caracteres do transcript',
        'max_transcript_chars_help' => 'Transcripts longos são truncados para a parte mais recente.',
        'system_prompt_override' => 'Override do system prompt',
        'system_prompt_override_help' => 'Opcional: substitui as instruções de extração padrão.',
    ],
];
