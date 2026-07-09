<?php

namespace App\Http\Controllers;

use App\Mcp\Tools\Concerns\ResolvesProjectId;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HookController extends Controller
{
    use ResolvesProjectId;

    public function ensure(Request $request): Response
    {
        $cwd = (string) $request->input('cwd', '');
        // Call the trait's ensureProject(): resolves from cwd and creates the Project.
        $pid = $this->ensureProject(null, $cwd !== '' ? $cwd : null);

        return response($pid."\n", 200)->header('Content-Type', 'text/plain');
    }
}
