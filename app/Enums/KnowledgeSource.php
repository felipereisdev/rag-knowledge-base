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
}
