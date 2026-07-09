<?php

// tests/Feature/Install/ClientInstallerTest.php

use App\Services\Install\ClientInstaller;
use Illuminate\Support\Facades\File;

function tmpTarget(): string
{
    $dir = sys_get_temp_dir().'/rag-install-'.bin2hex(random_bytes(4));
    File::makeDirectory($dir, 0777, true);

    return $dir;
}

it('substitutes placeholders', function () {
    $installer = new ClientInstaller(base_path('stubs/client'));
    $out = $installer->substitute('url=__RAG_URL__ token=__RAG_TOKEN__', 'http://x:8080', 'secret');
    expect($out)->toBe('url=http://x:8080 token=secret');
});

it('installs Claude artifacts with substituted config and rag-only mcp', function () {
    $target = tmpTarget();
    $installer = new ClientInstaller(base_path('stubs/client'));

    $installer->install($target, ['claude'], 'http://localhost:8080', 'tok123');

    expect(File::exists("$target/.claude/hooks/session-start.sh"))->toBeTrue();
    expect(File::exists("$target/.claude/hooks/lib/rag-core.sh"))->toBeTrue();

    $cfg = File::get("$target/.claude/hooks/config.sh");
    expect($cfg)->toContain('http://localhost:8080')->and($cfg)->toContain('tok123')
        ->and($cfg)->not->toContain('__RAG_URL__');

    $mcp = json_decode(File::get("$target/.mcp.json"), true);
    expect($mcp['mcpServers'])->toHaveKey('rag')->and($mcp['mcpServers'])->not->toHaveKey('martis');

    File::deleteDirectory($target);
});

it('merges into an existing .mcp.json without clobbering', function () {
    $target = tmpTarget();
    File::put("$target/.mcp.json", json_encode(['mcpServers' => ['other' => ['url' => 'x']]]));
    $installer = new ClientInstaller(base_path('stubs/client'));

    $installer->install($target, ['claude'], 'http://localhost:8080', 'tok');

    $mcp = json_decode(File::get("$target/.mcp.json"), true);
    expect($mcp['mcpServers'])->toHaveKeys(['other', 'rag']);

    File::deleteDirectory($target);
});

it('is idempotent on a second run', function () {
    $target = tmpTarget();
    $installer = new ClientInstaller(base_path('stubs/client'));
    $installer->install($target, ['claude'], 'http://localhost:8080', 'tok');
    $first = File::get("$target/.mcp.json");

    $installer->install($target, ['claude'], 'http://localhost:8080', 'tok');
    $second = File::get("$target/.mcp.json");

    expect($second)->toBe($first);
    File::deleteDirectory($target);
});

it('preserves an existing SessionStart hook when merging settings.json', function () {
    $target = tmpTarget();
    File::makeDirectory("$target/.claude", 0777, true, true);
    File::put("$target/.claude/settings.json", json_encode([
        'hooks' => [
            'SessionStart' => [
                ['hooks' => [['type' => 'command', 'command' => 'USER_OWN.sh']]],
            ],
        ],
    ]));
    $installer = new ClientInstaller(base_path('stubs/client'));

    $installer->install($target, ['claude'], 'http://localhost:8080', 'tok');

    $settings = json_decode(File::get("$target/.claude/settings.json"), true);
    $sessionStart = $settings['hooks']['SessionStart'];
    $commands = array_map(
        fn ($entry) => $entry['hooks'][0]['command'] ?? null,
        $sessionStart
    );

    expect($commands)->toContain('USER_OWN.sh');
    expect(collect($commands)->contains(fn ($c) => str_contains((string) $c, 'session-start.sh')))->toBeTrue();

    $first = File::get("$target/.claude/settings.json");
    $installer->install($target, ['claude'], 'http://localhost:8080', 'tok');
    $second = File::get("$target/.claude/settings.json");

    expect($second)->toBe($first);

    File::deleteDirectory($target);
});
