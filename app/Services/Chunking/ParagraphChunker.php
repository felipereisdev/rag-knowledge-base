<?php

namespace App\Services\Chunking;

class ParagraphChunker
{
    public function __construct(
        private readonly int $maxChars = 0,
    ) {}

    /**
     * @return array<Chunk>
     */
    public function chunk(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        // Split by blank lines (2+ newlines)
        $paragraphs = preg_split('/\n\s*\n/', $content) ?: [];
        $paragraphs = array_filter(array_map('trim', $paragraphs), fn ($p) => $p !== '');

        if (empty($paragraphs)) {
            return [];
        }

        $chunks = [];
        $currentContent = '';
        $index = 0;
        $currentHeading = '';

        foreach ($paragraphs as $paragraph) {
            // Detect heading - store as prefix, don't add to content
            if (preg_match('/^(#{1,6})\s+(.+)$/', $paragraph)) {
                $currentHeading = $paragraph;
                continue;
            }

            $candidate = $currentContent . $paragraph;

            if (strlen($candidate) > $this->maxChars && $currentContent !== '') {
                $chunks[] = new Chunk($this->withHeading($currentHeading, trim($currentContent)), $index++);
                $currentContent = $paragraph . "\n\n";
            } else {
                $currentContent = $candidate . "\n\n";
            }
        }

        if (trim($currentContent) !== '') {
            $chunks[] = new Chunk($this->withHeading($currentHeading, trim($currentContent)), $index++);
        }

        return $chunks;
    }

    private function withHeading(string $heading, string $content): string
    {
        return $heading !== '' ? $heading . "\n\n" . $content : $content;
    }
}
