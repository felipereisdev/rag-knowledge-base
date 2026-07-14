<?php

namespace App\Enums;

enum ProjectLanguage: string
{
    case English = 'en';
    case Portuguese = 'pt';
    case BrazilianPortuguese = 'pt-BR';
    case EuropeanPortuguese = 'pt_PT';
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
     * The language's English name, for use inside an LLM prompt.
     *
     * Deliberately not `__('rag.languages.*')`: those render in the admin's UI
     * locale, so an operator browsing Martis in pt_BR would flip the extraction
     * prompt's own wording. The prompt is written in English and must name the
     * target language in English no matter who triggered the run.
     */
    public function promptName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Portuguese => 'Portuguese',
            self::BrazilianPortuguese => 'Brazilian Portuguese',
            self::EuropeanPortuguese => 'European Portuguese',
            self::Spanish => 'Spanish',
        };
    }

    /**
     * Resolve a stored `projects.language` value, which is a free-text column:
     * the Martis Select writes the case values verbatim (note `pt-BR` uses a
     * dash while `pt_PT` uses an underscore), and `rag_update_project` accepts
     * whatever string the caller sends. So match on a normalized form rather
     * than `tryFrom()`, which would miss `pt_BR`, `PT-BR`, and friends.
     */
    public static function tryFromCode(?string $code): ?self
    {
        $normalize = static fn (string $value): string => str_replace('_', '-', strtolower(trim($value)));
        $needle = $normalize((string) $code);

        foreach (self::cases() as $case) {
            if ($normalize($case->value) === $needle) {
                return $case;
            }
        }

        return null;
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
