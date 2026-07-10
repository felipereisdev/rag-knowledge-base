<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Queue;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep the suite hermetic: under QUEUE_CONNECTION=sync, creating a
        // content-bearing KnowledgeEntry would synchronously dispatch
        // IndexEntryJob -> EntryIndexer -> the real embedder at localhost:8001.
        // Fake the queue globally so tests never hit it by accident; tests
        // that already call Queue::fake() themselves are unaffected.
        Queue::fake();
    }
}
