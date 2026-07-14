<?php

namespace App\Services\Condense;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class ClaudeSdkExtractor implements KnowledgeExtractor
{
    public function __construct(
        private readonly ExtractionPrompt $prompt,
        private readonly CandidateParser $parser,
        private readonly string $model,
        private readonly ?string $override,
        private readonly string $binary = 'claude',
    ) {}

    public function extract(string $transcript, ?string $language): array
    {
        $bin = (new ExecutableFinder)->find($this->binary);
        if ($bin === null) {
            Log::warning('ClaudeSdkExtractor: claude binary not found; skipping condense', [
                'binary' => $this->binary,
            ]);

            return [];
        }

        $fullPrompt = $this->prompt->instructions($this->override, $language)
            ."\n\n---TRANSCRIPT---\n".$transcript;

        $process = new Process([$bin, '-p', $fullPrompt, '--output-format', 'json', '--model', $this->model]);
        $process->setTimeout(180);

        try {
            $process->run();
        } catch (Throwable $e) {
            Log::warning('ClaudeSdkExtractor: process failed', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $process->isSuccessful()) {
            Log::warning('ClaudeSdkExtractor: non-zero exit', [
                'exit' => $process->getExitCode(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 500),
            ]);

            return [];
        }

        $decoded = json_decode($process->getOutput(), true);
        $text = is_array($decoded) ? (string) ($decoded['result'] ?? '') : '';

        return $this->parser->parse($text);
    }
}
