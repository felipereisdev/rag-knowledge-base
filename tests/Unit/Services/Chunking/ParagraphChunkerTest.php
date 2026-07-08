<?php

use App\Services\Chunking\ParagraphChunker;
use App\Services\Chunking\Chunk;

describe('ParagraphChunker', function () {
    it('splits content by blank lines', function () {
        $chunker = new ParagraphChunker();
        $content = "First paragraph.\n\nSecond paragraph.\n\nThird paragraph.";

        $chunks = $chunker->chunk($content);

        expect($chunks)->toHaveCount(3)
            ->and($chunks[0]->content)->toBe('First paragraph.')
            ->and($chunks[0]->index)->toBe(0)
            ->and($chunks[1]->content)->toBe('Second paragraph.')
            ->and($chunks[1]->index)->toBe(1)
            ->and($chunks[2]->content)->toBe('Third paragraph.')
            ->and($chunks[2]->index)->toBe(2);
    });

    it('groups paragraphs up to max chars', function () {
        $chunker = new ParagraphChunker(maxChars: 100);
        // Each paragraph is 30 chars; 3 fit in 100 chars (90 + 2 separators)
        $content = str_repeat('A', 30) . "\n\n" . str_repeat('B', 30) . "\n\n" . str_repeat('C', 30) . "\n\n" . str_repeat('D', 30);

        $chunks = $chunker->chunk($content);

        expect($chunks)->toHaveCount(2)
            ->and($chunks[0]->content)->toContain('A')
            ->and($chunks[0]->content)->toContain('B')
            ->and($chunks[0]->content)->toContain('C')
            ->and($chunks[1]->content)->toContain('D');
    });

    it('preserves headings as prefix', function () {
        $chunker = new ParagraphChunker();
        $content = "# Section Title\n\nParagraph under the section.";

        $chunks = $chunker->chunk($content);

        expect($chunks)->toHaveCount(1)
            ->and($chunks[0]->content)->toContain('# Section Title')
            ->and($chunks[0]->content)->toContain('Paragraph under the section.');
    });

    it('handles empty content', function () {
        $chunker = new ParagraphChunker();

        $chunks = $chunker->chunk('');

        expect($chunks)->toBeEmpty();
    });

    it('handles single paragraph', function () {
        $chunker = new ParagraphChunker();

        $chunks = $chunker->chunk('Just one paragraph.');

        expect($chunks)->toHaveCount(1)
            ->and($chunks[0]->content)->toBe('Just one paragraph.')
            ->and($chunks[0]->index)->toBe(0);
    });
});