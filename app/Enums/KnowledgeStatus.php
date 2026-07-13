<?php

namespace App\Enums;

enum KnowledgeStatus: string
{
    case Classifying = 'classifying';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
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
                static fn (self $status): string => __('rag.statuses.'.$status->value),
                self::cases(),
            ),
        );
    }
}
