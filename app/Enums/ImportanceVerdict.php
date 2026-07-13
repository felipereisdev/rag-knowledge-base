<?php

namespace App\Enums;

enum ImportanceVerdict: string
{
    case Important = 'important';
    case NotImportant = 'not_important';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $verdict): string => $verdict->value,
            self::cases(),
        );
    }
}
