<?php

namespace App\Services\Speech;

use App\Services\Speech\Contracts\SpeechToText;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Whisper, over plain HTTP.
 *
 * No SDK: this is one multipart POST and one field of the reply, and a package
 * for it would be a dependency to keep current for the sake of six lines.
 */
class OpenAiSpeechToText implements SpeechToText
{
    public const ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';

    public function isConfigured(): bool
    {
        return filled(config('familyhub.openai.key'));
    }

    public function transcribe(string $audio, string $filename = 'speech.webm', string $language = 'en'): string
    {
        if (! $this->isConfigured()) {
            throw new SpeechException('Listening is not set up yet — no OpenAI key.');
        }

        if ($audio === '') {
            throw new SpeechException('I didn\'t catch that.');
        }

        try {
            $response = Http::withToken((string) config('familyhub.openai.key'))
                ->timeout((int) config('familyhub.openai.timeout'))
                ->attach('file', $audio, $filename)
                ->post(self::ENDPOINT, [
                    'model' => (string) config('familyhub.openai.stt_model'),
                    // Told rather than guessed: given the choice, Whisper will
                    // occasionally decide a mumbled English sentence is Welsh.
                    'language' => $language,
                    'response_format' => 'json',
                ]);
        } catch (ConnectionException) {
            throw new SpeechException('I couldn\'t reach the internet to listen.');
        }

        if ($response->failed()) {
            throw new SpeechException($this->reason($response->status()));
        }

        return trim((string) $response->json('text', ''));
    }

    /** Something true, in words, for a screen with no console. */
    protected function reason(int $status): string
    {
        return match (true) {
            $status === 401 => 'The OpenAI key was refused.',
            $status === 429 => 'Too many requests just now — try again in a moment.',
            $status >= 500 => 'OpenAI is having trouble. Try again in a moment.',
            default => 'I couldn\'t make sense of that recording.',
        };
    }
}
