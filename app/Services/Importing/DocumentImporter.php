<?php

namespace App\Services\Importing;

use App\Models\KnowledgeEntry;
use App\Models\Tag;

class DocumentImporter
{
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
            $entry = KnowledgeEntry::create([
                'project_id' => $projectId,
                'title' => $section['title'],
                'content' => $section['content'],
                'category' => $category,
                'source' => 'import',
                'status' => 'pending',
            ]);

            if ($tags !== null) {
                foreach ($tags as $tagName) {
                    $tag = Tag::firstOrCreate([
                        'project_id' => $projectId,
                        'name' => $tagName,
                    ]);
                    $entry->tags()->attach($tag->id);
                }
            }

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
        $currentTitle = null;
        $currentBuffer = [];

        foreach ($lines as $line) {
            if (preg_match('/^#\s+(.+)$/', $line, $m)) {
                $this->flushSection($sections, $currentTitle, $currentBuffer);
                $currentTitle = trim($m[1]);
            } elseif (preg_match('/^##\s+(.+)$/', $line, $m)) {
                $this->flushSection($sections, $currentTitle, $currentBuffer);
                $currentTitle = ($currentTitle ?? '').' / '.trim($m[1]);
                $currentTitle = ltrim($currentTitle, ' /');
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
