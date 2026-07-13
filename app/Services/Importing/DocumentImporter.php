<?php

namespace App\Services\Importing;

use App\Services\Knowledge\KnowledgeWriter;

class DocumentImporter
{
    public function __construct(private readonly KnowledgeWriter $writer) {}

    /**
     * Import a .md or .txt file, splitting into entries by H1/H2 (markdown)
     * or creating a single entry (txt).
     *
     * @param  array<string>|null  $tags
     * @return array<string> Entry IDs created.
     *
     * @throws \InvalidArgumentException
     */
    public function import(string $projectId, string $filePath, string $category = 'insight', ?array $tags = null): array
    {
        if (! file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (! in_array($extension, ['md', 'txt'], true)) {
            throw new \InvalidArgumentException("Unsupported file type: .{$extension}. Only .md and .txt are supported.");
        }

        $content = (string) file_get_contents($filePath);

        $sections = $extension === 'md'
            ? $this->splitMarkdown($content)
            : [['title' => basename($filePath), 'content' => $content]];

        $entryIds = [];
        foreach ($sections as $section) {
            $entry = $this->writer->store(
                projectId: $projectId,
                title: $section['title'],
                content: $section['content'],
                category: $category,
                source: 'import',
                tags: $tags ?? [],
            );

            $entryIds[] = $entry->id;
        }

        return $entryIds;
    }

    /**
     * Split markdown content by H1 and H2 headers.
     *
     * Each H1 starts a new entry; H2 becomes a sub-entry with the parent H1
     * title as a prefix. Content before the first H1 (if any) is attached to
     * an "Intro" entry.
     *
     * @return array<array{title: string, content: string}>
     */
    private function splitMarkdown(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $sections = [];
        $currentH1 = null;
        $currentTitle = null;
        $currentBuffer = [];

        foreach ($lines as $line) {
            if (preg_match('/^#\s+(.+)$/', $line, $m)) {
                $this->flushSection($sections, $currentTitle, $currentBuffer);
                $currentH1 = trim($m[1]);
                $currentTitle = $currentH1;
            } elseif (preg_match('/^##\s+(.+)$/', $line, $m)) {
                $this->flushSection($sections, $currentTitle, $currentBuffer);
                $heading = trim($m[1]);
                $currentTitle = $currentH1 !== null ? "{$currentH1} / {$heading}" : $heading;
            } else {
                $currentBuffer[] = $line;
            }
        }
        $this->flushSection($sections, $currentTitle, $currentBuffer);

        if ($sections === []) {
            return [['title' => 'Imported Document', 'content' => $content]];
        }

        return $sections;
    }

    /**
     * @param  array<array{title: string, content: string}>  $sections
     * @param  array<int, string>  $buffer
     */
    private function flushSection(array &$sections, ?string &$title, array &$buffer): void
    {
        $body = trim(implode("\n", $buffer));
        if ($title !== null && $body !== '') {
            $sections[] = ['title' => $title, 'content' => $body];
        }
        $buffer = [];
    }
}
