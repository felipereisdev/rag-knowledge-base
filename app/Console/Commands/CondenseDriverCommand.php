<?php

namespace App\Console\Commands;

use App\Models\CondenseSetting;
use Illuminate\Console\Command;

class CondenseDriverCommand extends Command
{
    protected $signature = 'rag:condense-driver';

    protected $description = 'Print the current condense extractor driver (claude_sdk|api); used by bin/condense-worker.sh to place the worker.';

    public function handle(): int
    {
        $this->line(CondenseSetting::current()->driver);

        return self::SUCCESS;
    }
}
