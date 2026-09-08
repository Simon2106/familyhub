<?php

namespace App\Services\Speech;

use App\Services\Speech\Contracts\TextToSpeech;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The answer, read out.
 *
 * Everything here is best-effort. A wall that shows the answer and says
 * nothing is a wall that works; one that fails to answer because it could not
 * find its voice is not, so every failure ends in null and a log line.
 */
class OpenAiTextToSpeech implements TextToSpeech
{
    public const ENDPOINT = 'https://api.openai.com/v1/audio/speech';

    /** Longer than any answer this assistant is meant to give. */
    public const MAX_CHARACTERS = 800;

    public function isConfigured(): bool
    {
        return filled(config('familyhub.openai.key'));
    }

    public function speak(string $text): ?string
    {
        $text = trim($text);

        if (! $this->isConfigured() || $text === '') {
            return null;
        }

        try {
            $response = Http::withToken((string) config('familyhub.openai.key'))
                ->timeout((int) config('familyhub.openai.timeout'))
                ->post(self::ENDPOINT, [
                    'model' => (string) config('familyhub.openai.tts_model'),
                    'voice' => (string) config('familyhub.openai.voice'),
                    'input' => mb_substr($text, 0, self::MAX_CHARACTERS),
                    'response_format' => 'mp3',
                ]);

            if ($response->failed()) {
                Log::warning('The wall could not be given a voice', ['status' => $response->status()]);

                return null;
            }

            $audio = $response->body();

            return $audio === '' ? null : $audio;
        } catch (Throwable $e) {
            Log::warning('The wall could not be given a voice', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
