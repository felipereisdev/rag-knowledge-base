<?php

namespace App\Enums;

enum ProjectLanguage: string
{
    case English = 'en';
    case Portuguese = 'pt';
    case Spanish = 'es';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $language): string => $language->value,
            self::cases(),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            self::values(),
            array_map(
                static fn (self $language): string => __('rag.languages.'.$language->value),
                self::cases(),
            ),
        );
    }
}
