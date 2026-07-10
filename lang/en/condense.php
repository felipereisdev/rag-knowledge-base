<?php

return [
    'fields' => [
        'enabled' => 'Enabled',
        'enabled_help' => 'Extract knowledge from sessions on Stop.',
        'driver' => 'Extractor driver',
        'driver_help' => 'Claude SDK reuses your Claude subscription; API uses a provider key.',
        'provider' => 'API provider',
        'provider_help' => 'Only used when the driver is "API provider".',
        'model' => 'Model',
        'model_help' => 'e.g. claude-haiku-4-5-20251001',
        'min_dedup_score' => 'Dedup threshold',
        'min_dedup_score_help' => 'Skip a candidate when a stored entry is at least this similar (0-1).',
        'max_transcript_chars' => 'Max transcript characters',
        'max_transcript_chars_help' => 'Longer transcripts are truncated to the most recent part.',
        'system_prompt_override' => 'System prompt override',
        'system_prompt_override_help' => 'Optional: replace the default extraction instructions.',
    ],
];
