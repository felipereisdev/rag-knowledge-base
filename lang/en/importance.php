<?php

return [
    'resource' => [
        'label' => 'Importance Classifier',
        'singular_label' => 'Importance Classifier',
        'subtitle' => 'How captured knowledge is judged before it reaches the approval queue.',
    ],
    'fields' => [
        'mode' => 'Mode',
        'mode_help' => 'Off: capture everything without judging. Shadow: judge and record, but never reject. Enforce: reject entries judged unimportant.',
        'threshold' => 'Threshold',
        'threshold_help' => 'Score from 0 to 100 an entry must reach to be judged important.',
        'active_model' => 'Active model',
        'active_model_help' => 'Set in configuration, not here.',
        'prompt_version' => 'Prompt version',
        'prompt_version_help' => 'Set in configuration, not here.',
        'rules_version' => 'Rules version',
        'rules_version_help' => 'Set in configuration, not here.',
    ],
    'modes' => [
        'off' => 'Off',
        'shadow' => 'Shadow',
        'enforce' => 'Enforce',
    ],
    'verdicts' => [
        'important' => 'Important',
        'not_important' => 'Not important',
    ],
    'audit' => [
        'section' => 'Importance',
        'score' => 'Importance score',
        'verdict' => 'Verdict',
        'mode' => 'Mode at classification',
        'reasons' => 'Reasons',
        'rules' => 'Triggered rules',
        'model' => 'Model',
        'prompt_version' => 'Prompt version',
        'rules_version' => 'Rules version',
        'cache' => 'Cache',
        'cache_hit' => 'Reused a previous assessment',
        'cache_miss' => 'Freshly classified',
        'error' => 'Classification error',
        'reason_line' => ':criterion: :explanation',
        'rule_line' => ':id (:adjustment): :reason',
        'error_line' => ':code: :message',
    ],
    'actions' => [
        'approve_blocked' => '{1} That entry is still being classified and cannot be approved yet.|[2,*] :count entries are still being classified and cannot be approved yet.',
        'approve_partial' => '{1} Approved :approved. One entry is still being classified and was skipped.|[2,*] Approved :approved. :count entries are still being classified and were skipped.',
        'reject_blocked' => '{1} That entry is still being classified and cannot be rejected yet.|[2,*] :count entries are still being classified and cannot be rejected yet.',
        'reject_partial' => '{1} Rejected :rejected. One entry is still being classified and was skipped.|[2,*] Rejected :rejected. :count entries are still being classified and were skipped.',
    ],
    'dashboard' => [
        'classifying' => 'Being Classified',
    ],
];
