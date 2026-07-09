<?php

// tests/Feature/Install/RagInstallCommandTest.php

use Illuminate\Support\Facades\File;

it('installs the selected harness non-interactively via flags', function () {
    $target = sys_get_temp_dir().'/rag-cmd-'.bin2hex(random_bytes(4));
    File::makeDirectory($target, 0777, true);

    $this->artisan('rag:install', [
        '--target' => $target,
        '--harness' => 'claude',
        '--url' => 'http://localhost:8080',
        '--token' => 'tok',
    ])->assertExitCode(0);

    expect(File::exists("$target/.claude/hooks/stop.sh"))->toBeTrue();
    expect(File::exists("$target/.mcp.json"))->toBeTrue();

    File::deleteDirectory($target);
});

it('fails clearly when the target does not exist', function () {
    $this->artisan('rag:install', [
        '--target' => '/no/such/dir/xyz',
        '--harness' => 'claude',
        '--url' => 'http://localhost:8080',
        '--token' => 'tok',
    ])->assertExitCode(1);
});
