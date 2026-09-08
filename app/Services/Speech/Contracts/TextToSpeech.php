<?php

namespace App\Services\Speech\Contracts;

/**
 * Turns an answer into something the kitchen can hear.
 *
 * Never throws: speech is the part of this feature the household can most
 * easily do without. An answer nobody reads aloud is still an answer on the
 * screen, so a failure here returns null and the display simply stays quiet.
 */
interface TextToSpeech
{
    public function isConfigured(): bool;

    /** MP3 bytes, or null if it cannot speak right now. */
    public function speak(string $text): ?string;
}
