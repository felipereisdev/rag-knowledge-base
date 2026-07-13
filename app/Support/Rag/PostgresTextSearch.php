<?php

namespace App\Support\Rag;

final class PostgresTextSearch
{
    public static function configForLanguage(?string $language): string
    {
        $normalized = str_replace('_', '-', strtolower((string) $language));

        return match (true) {
            $normalized === 'pt', str_starts_with($normalized, 'pt-') => 'portuguese',
            $normalized === 'es', str_starts_with($normalized, 'es-') => 'spanish',
            default => 'english',
        };
    }
}
