<?php

// app/Console/Commands/RagInstallCommand.php

namespace App\Console\Commands;

use App\Services\Install\ClientInstaller;
use Illuminate\Console\Command;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

class RagInstallCommand extends Command
{
    protected $signature = 'rag:install
        {--target= : Path to the client project (default: cwd)}
        {--harness= : Comma-separated: claude,codex,cursor,opencode}
        {--url= : RAG server base URL}
        {--token= : RAG hook bearer token}';

    protected $description = 'Provision a client project to use the RAG server (hooks + skill + rag MCP).';

    private const HARNESSES = ['claude', 'codex', 'cursor', 'opencode'];

    public function handle(ClientInstaller $installer): int
    {
        $target = (string) ($this->option('target') ?: getcwd());
        if (! is_dir($target)) {
            $this->error("Target directory does not exist: {$target}");

            return self::FAILURE;
        }

        $harnesses = $this->resolveHarnesses();
        if ($harnesses === []) {
            $this->error('No valid harness selected.');

            return self::FAILURE;
        }

        $url = (string) ($this->option('url') ?: text('RAG server URL', default: 'http://localhost:8080'));
        $token = (string) ($this->option('token') ?: text('RAG hook token', required: true));

        $written = $installer->install($target, $harnesses, $url, $token);

        $this->info('Installed RAG integration into '.$target);
        foreach ($written as $rel) {
            $this->line('  + '.$rel);
        }

        if (in_array('codex', $harnesses, true)) {
            $this->warn('Codex: run `/hooks` in the project once to trust the new hooks.');
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function resolveHarnesses(): array
    {
        $opt = (string) $this->option('harness');
        if ($opt !== '') {
            $chosen = array_map('trim', explode(',', $opt));
        } else {
            $chosen = multiselect('Which harness(es)?', self::HARNESSES, required: true);
        }

        return array_values(array_intersect(self::HARNESSES, $chosen));
    }
}
