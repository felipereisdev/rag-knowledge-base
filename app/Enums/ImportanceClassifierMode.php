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

    /**
     * Machine value => translated label, for the administration Select.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            self::values(),
            array_map(
                static fn (self $mode): string => __('importance.modes.'.$mode->value),
                self::cases(),
            ),
        );
    }
}
