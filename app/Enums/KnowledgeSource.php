<?php

namespace App\Enums;

enum KnowledgeSource: string
{
    case Condense = 'condense';
    case Mcp = 'mcp';
    case Cli = 'cli';
    case Import = 'import';
    case Manual = 'manual';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $source): string => $source->value,
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
                static fn (self $source): string => __('rag.sources.'.$source->value),
                self::cases(),
            ),
        );
    }
}
