<?php

namespace App\Enums;

enum ImportanceClassifierMode: string
{
    case Off = 'off';
    case Shadow = 'shadow';
    case Enforce = 'enforce';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $mode): string => $mode->value,
            self::cases(),
        );
    }
}
