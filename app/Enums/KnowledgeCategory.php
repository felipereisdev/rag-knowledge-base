<?php

namespace App\Enums;

enum KnowledgeCategory: string
{
    case BusinessRule = 'business-rule';
    case DesignDecision = 'design-decision';
    case Architecture = 'architecture';
    case Documentation = 'documentation';
    case Insight = 'insight';
    case Convention = 'convention';
    case Constraint = 'constraint';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $category): string => $category->value,
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
                static fn (self $category): string => __('rag.categories.'.$category->value),
                self::cases(),
            ),
        );
    }
}
