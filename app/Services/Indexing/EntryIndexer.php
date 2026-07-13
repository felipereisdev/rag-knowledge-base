<?php

namespace App\Services\Indexing;

use App\Models\KnowledgeEntry;
use App\Services\Chunking\ParagraphChunker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Throwable;

class EntryIndexer
{
    /**
     * The provider used for embedding generation.
     */
    public const string PROVIDER = 'local-embedder';

    public function __construct(
        private readonly ParagraphChunker $chunker,
    ) {}

    /**
     * Chunk the entry's content, generate embeddings, and persist chunk rows.
     *
     * Existing chunks for the entry are replaced atomically. When the content
     * is empty, any existing chunks are removed and the embedder is not called.
     */
    public function index(KnowledgeEntry $entry): void
    {
        $embeddedContent = $entry->content;
        $embeddedProjectId = $entry->project_id;
        $chunks = $this->chunker->chunk($embeddedContent);
        $vectors = [];

        if ($chunks !== []) {
            $texts = array_map(fn ($c) => $c->content, $chunks);

            try {
                $response = Embeddings::for($texts)->generate((string) config('rag.embeddings.provider', self::PROVIDER));
                $vectors = $response->embeddings;
            } catch (Throwable $e) {
                Log::error('EntryIndexer: embedding generation failed', [
                    'entry_id' => $entry->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        DB::transaction(function () use ($entry, $embeddedContent, $embeddedProjectId, $chunks, $vectors): void {
            $currentEntry = KnowledgeEntry::query()->whereKey($entry->id)->lockForUpdate()->first();

            if ($currentEntry === null
                || ! in_array($currentEntry->status, ['approved', 'pending'], true)
                || $currentEntry->content !== $embeddedContent
                || $currentEntry->project_id !== $embeddedProjectId) {
                return;
            }

            DB::table('chunk_embeddings')->where('entry_id', $entry->id)->delete();

            $rows = [];
            foreach ($chunks as $i => $chunk) {
                $rows[] = [
                    'entry_id' => $entry->id,
                    'project_id' => $entry->project_id,
                    'chunk_index' => $chunk->index,
                    'content' => $chunk->content,
                    'embedding' => '['.implode(',', $vectors[$i]).']',
                    'created_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('chunk_embeddings')->insert($rows);
            }
        });
    }
}
