<?php

namespace Tests\Support;

use App\Services\Speech\Contracts\SpeechToText;
use App\Services\Speech\SpeechException;

/** Stands in for Whisper. */
class FakeSpeechToText implements SpeechToText
{
    public bool $configured = true;

    public string $heard = 'What is for tea?';

    public ?string $throw = null;

    /** @var list<array{audio: string, language: string}> */
    public array $calls = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function transcribe(string $audio, string $filename = 'speech.webm', string $language = 'en'): string
    {
        $this->calls[] = ['audio' => $audio, 'language' => $language];

        if ($this->throw !== null) {
            throw new SpeechException($this->throw);
        }

        return $this->heard;
    }
}
