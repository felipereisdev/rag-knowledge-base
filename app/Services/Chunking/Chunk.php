<?php

namespace App\Services\Chunking;

class Chunk
{
    public function __construct(
        public readonly string $content,
        public readonly int $index,
    ) {}
}