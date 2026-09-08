<?php

namespace Tests\Support;

use App\Services\Speech\Contracts\TextToSpeech;

/** Stands in for the voice. Returns bytes that are obviously not real audio. */
class FakeTextToSpeech implements TextToSpeech
{
    public bool $configured = true;

    /** Null models the silent failure the real one is required to make. */
    public ?string $audio = 'MP3-BYTES';

    /** @var list<string> */
    public array $spoke = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function speak(string $text): ?string
    {
        $this->spoke[] = $text;

        return $this->audio;
    }
}
