<?php

namespace App\Services\Speech\Contracts;

use App\Services\Speech\SpeechException;

/**
 * Turns a recording into words.
 *
 * An interface because the provider is an implementation detail with a short
 * shelf life: this is one HTTP call behind one method, and swapping it for a
 * local model on the Pi should not touch anything that knows about households.
 */
interface SpeechToText
{
    public function isConfigured(): bool;

    /**
     * @param  string  $audio  the recording's bytes
     * @param  string  $filename  what to call it — the provider sniffs the container from the extension
     * @return string what was said, trimmed; empty when nothing was
     *
     * @throws SpeechException
     */
    public function transcribe(string $audio, string $filename = 'speech.webm', string $language = 'en'): string;
}
