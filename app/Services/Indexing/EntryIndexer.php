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
        $chunks = $this->chunker->chunk($entry->content);

        if ($chunks === []) {
            DB::table('chunk_embeddings')->where('entry_id', $entry->id)->delete();

            return;
        }

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

        DB::transaction(function () use ($entry, $chunks, $vectors): void {
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

            DB::table('chunk_embeddings')->insert($rows);
        });
    }
}
