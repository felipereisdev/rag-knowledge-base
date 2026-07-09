<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a RAG request cannot determine which project it targets.
 *
 * The RAG server runs as a shared HTTP service (often in Docker) and cannot
 * see the client's filesystem, so it must never guess the project from its own
 * working directory. When no identity is supplied, we fail loudly with guidance
 * rather than silently resolving to the container's cwd (e.g. "public").
 */
class ProjectNotIdentifiedException extends RuntimeException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message !== '' ? $message : (
            'Could not identify the project. The RAG server cannot see the client '.
            'filesystem over HTTP, so it will not guess from its own working directory. '.
            'Connect through a project-scoped URL (http://<server>/mcp/rag/<project-id>) '.
            'or pass an explicit project_id argument.'
        ));
    }
}
