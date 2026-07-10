<?php

namespace App\Enums;

enum ExtractorDriver: string
{
    case ClaudeSdk = 'claude_sdk';
    case Api = 'api';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::ClaudeSdk->value => 'Claude SDK (subscription)',
            self::Api->value => 'API provider',
        ];
    }
}
