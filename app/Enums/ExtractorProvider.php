<?php

namespace App\Enums;

enum ExtractorProvider: string
{
    case Anthropic = 'anthropic';
    case Openai = 'openai';
    case Gemini = 'gemini';
    case Openrouter = 'openrouter';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Anthropic->value => 'Anthropic',
            self::Openai->value => 'OpenAI',
            self::Gemini->value => 'Gemini',
            self::Openrouter->value => 'OpenRouter',
        ];
    }
}
