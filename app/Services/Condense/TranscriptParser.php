<?php

namespace App\Services\Condense;

class TranscriptParser
{
    public function parse(string $path, int $maxChars): string
    {
        if (! is_readable($path)) {
            return '';
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return '';
        }

        $parts = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }
            $type = $event['type'] ?? null;
            if ($type !== 'user' && $type !== 'assistant') {
                continue;
            }
            $text = $this->extractText($event['message']['content'] ?? null);
            if ($text === '') {
                continue;
            }
            $parts[] = strtoupper($type).': '.$text;
        }
        fclose($handle);

        $joined = implode("\n\n", $parts);

        if (mb_strlen($joined) > $maxChars) {
            $joined = mb_substr($joined, -$maxChars);
        }

        return $joined;
    }

    private function extractText(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (! is_array($content)) {
            return '';
        }

        $texts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }

        return trim(implode("\n", $texts));
    }
}
