<?php

namespace App\Services\Install;

use Illuminate\Support\Facades\File;

class ClientInstaller
{
    private string $url = '';

    private string $token = '';

    private string $project = '';

    public function __construct(private string $stubsRoot) {}

    /**
     * @param  array<int, string>  $harnesses
     * @return array<int, string>
     */
    public function install(string $target, array $harnesses, string $url, string $token, string $project): array
    {
        $this->url = $url;
        $this->token = $token;
        $this->project = $project;

        $written = [];
        foreach ($harnesses as $harness) {
            $method = 'install'.ucfirst($harness);
            $written = array_merge($written, $this->{$method}($target));
        }

        return $written;
    }

    public function substitute(string $contents, string $url, string $token, string $project): string
    {
        return str_replace(
            ['__RAG_URL__', '__RAG_TOKEN__', '__RAG_PROJECT__'],
            [$url, $token, $project],
            $contents,
        );
    }

    /** Substitute using the state of the current install() run. */
    private function fill(string $contents): string
    {
        return $this->substitute($contents, $this->url, $this->token, $this->project);
    }

    /** Copy a stub file with placeholder substitution, preserving exec bit for .sh. */
    private function copyFile(string $from, string $to): void
    {
        File::ensureDirectoryExists(dirname($to));
        File::put($to, $this->fill(File::get($from)));
        if (str_ends_with($to, '.sh')) {
            @chmod($to, 0755);
        }
    }

    /**
     * Copy the shared shell core (lib/ + config.sh) into a hooks dir.
     *
     * @return array<int, string>
     */
    private function copyShared(string $hooksDir): array
    {
        $this->copyFile("{$this->stubsRoot}/hooks/lib/rag-core.sh", "$hooksDir/lib/rag-core.sh");
        $this->copyFile("{$this->stubsRoot}/hooks/config.sh", "$hooksDir/config.sh");

        return ["$hooksDir/lib/rag-core.sh", "$hooksDir/config.sh"];
    }

    /**
     * @param  array<array-key, mixed>  $incoming
     */
    public function mergeJsonFile(string $path, array $incoming): void
    {
        $existing = File::exists($path)
            ? (json_decode(File::get($path), true) ?: [])
            : [];
        $merged = $this->deepMerge($existing, $incoming);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** Merge a substituted JSON stub into a target JSON file. */
    private function mergeJsonStub(string $stub, string $target): void
    {
        $this->mergeJsonFile($target, json_decode($this->fill(File::get($stub)), true));
    }

    /**
     * @param  array<array-key, mixed>  $a
     * @param  array<array-key, mixed>  $b
     * @return array<array-key, mixed>
     */
    private function deepMerge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (is_array($v) && isset($a[$k]) && is_array($a[$k])) {
                $a[$k] = array_is_list($v)
                    ? $this->mergeLists($a[$k], $v)
                    : $this->deepMerge($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }

        return $a;
    }

    /**
     * Append items from $incoming that aren't already present in $existing
     * (compared by JSON representation), rather than merging by numeric index.
     * This keeps hook-event lists (e.g. .claude/settings.json's
     * hooks.SessionStart) non-destructive: a client's own hook entries
     * survive alongside RAG's, and re-running install() is idempotent.
     *
     * @param  array<int, mixed>  $existing
     * @param  array<int, mixed>  $incoming
     * @return array<int, mixed>
     */
    private function mergeLists(array $existing, array $incoming): array
    {
        $existingEncoded = array_map(fn ($item) => json_encode($item), $existing);

        foreach ($incoming as $item) {
            $encoded = json_encode($item);
            if (! in_array($encoded, $existingEncoded, true)) {
                $existing[] = $item;
                $existingEncoded[] = $encoded;
            }
        }

        return $existing;
    }

    public function appendMarkedBlock(string $path, string $block): void
    {
        $current = File::exists($path) ? File::get($path) : '';
        if (str_contains($current, '<!-- rag:begin -->')) {
            return; // already present — idempotent
        }
        $sep = ($current !== '' && ! str_ends_with($current, "\n")) ? "\n\n" : "\n";
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $current.$sep.$block."\n");
    }

    // ---- per-harness installers ----

    /**
     * @return array<int, string>
     */
    private function installClaude(string $t): array
    {
        $w = [];
        foreach (['session-start.sh', 'user-prompt.sh', 'stop.sh'] as $s) {
            $this->copyFile("{$this->stubsRoot}/claude/hooks/$s", "$t/.claude/hooks/$s");
            $w[] = ".claude/hooks/$s";
        }
        $w = array_merge($w, $this->copyShared("$t/.claude/hooks"));

        $this->copyFile("{$this->stubsRoot}/claude/skills/using-rag/SKILL.md", "$t/.claude/skills/using-rag/SKILL.md");
        $this->mergeJsonStub("{$this->stubsRoot}/claude/settings.json", "$t/.claude/settings.json");
        $this->mergeJsonStub("{$this->stubsRoot}/claude/mcp.json", "$t/.mcp.json");

        return array_merge($w, ['.claude/settings.json', '.mcp.json', '.claude/skills/using-rag/SKILL.md']);
    }

    /**
     * @return array<int, string>
     */
    private function installCodex(string $t): array
    {
        $w = [];
        foreach (['session-start.sh', 'user-prompt.sh', 'stop.sh'] as $s) {
            $this->copyFile("{$this->stubsRoot}/codex/hooks/$s", "$t/.codex/hooks/$s");
            $w[] = ".codex/hooks/$s";
        }
        $w = array_merge($w, $this->copyShared("$t/.codex/hooks"));
        $this->mergeJsonStub("{$this->stubsRoot}/codex/hooks.json", "$t/.codex/hooks.json");

        // Append the [mcp_servers.rag] TOML snippet if not already present.
        $tomlPath = "$t/.codex/config.toml";
        $snippet = $this->fill(File::get("{$this->stubsRoot}/codex/config.toml.snippet"));
        $existing = File::exists($tomlPath) ? File::get($tomlPath) : '';
        if (! str_contains($existing, '[mcp_servers.rag]')) {
            File::ensureDirectoryExists(dirname($tomlPath));
            File::put($tomlPath, rtrim($existing)."\n\n".$snippet."\n");
        }

        $this->appendMarkedBlock("$t/AGENTS.md", $this->fill(File::get("{$this->stubsRoot}/AGENTS.rag.md")));

        return array_merge($w, ['.codex/hooks.json', '.codex/config.toml', 'AGENTS.md']);
    }

    /**
     * @return array<int, string>
     */
    private function installCursor(string $t): array
    {
        $w = [];
        foreach (['session-start.sh', 'stop.sh'] as $s) {
            $this->copyFile("{$this->stubsRoot}/cursor/hooks/$s", "$t/.cursor/hooks/$s");
            $w[] = ".cursor/hooks/$s";
        }
        $w = array_merge($w, $this->copyShared("$t/.cursor/hooks"));
        $this->mergeJsonStub("{$this->stubsRoot}/cursor/hooks.json", "$t/.cursor/hooks.json");
        $this->mergeJsonStub("{$this->stubsRoot}/cursor/mcp.json", "$t/.cursor/mcp.json");
        $this->copyFile("{$this->stubsRoot}/cursor/cursorrules", "$t/.cursorrules");

        return array_merge($w, ['.cursor/hooks.json', '.cursor/mcp.json', '.cursorrules']);
    }

    /**
     * @return array<int, string>
     */
    private function installOpencode(string $t): array
    {
        $this->copyFile("{$this->stubsRoot}/opencode/plugin/rag.ts", "$t/.opencode/plugin/rag.ts");
        $this->mergeJsonStub("{$this->stubsRoot}/opencode/mcp.snippet.json", "$t/opencode.json");
        $this->appendMarkedBlock("$t/AGENTS.md", $this->fill(File::get("{$this->stubsRoot}/AGENTS.rag.md")));

        return ['.opencode/plugin/rag.ts', 'opencode.json', 'AGENTS.md'];
    }
}
