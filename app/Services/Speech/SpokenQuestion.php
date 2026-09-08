<?php

namespace App\Services\Speech;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One question asked out loud, while it is being answered.
 *
 * In the cache rather than the database, deliberately. The wall assistant has
 * the same transcript-free session model as the one on the phone: what the
 * family asked their kitchen is not a record anybody asked us to keep, and a
 * table of it would be one. This exists only so a queued job and a polling
 * display can talk about the same question, and it evaporates on its own.
 */
class SpokenQuestion
{
    /** Long enough for a slow answer, short enough to be forgotten. */
    public const TTL_MINUTES = 10;

    /** Where the recording and the spoken answer live while in use. */
    public const DISK = 'local';

    public const DIRECTORY = 'speech';

    protected function __construct(public readonly string $id) {}

    public static function start(): self
    {
        $question = new self((string) Str::uuid());

        $question->put(['status' => 'transcribing']);

        return $question;
    }

    /** An id off the wire, or null — never a guess at one. */
    public static function find(?string $id): ?self
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return Cache::has(self::key($id)) ? new self($id) : null;
    }

    /**
     * Where it has got to.
     *
     * @return array{status: string, transcript: ?string, answer: ?string, error: ?string, speaks: bool}
     */
    public function state(): array
    {
        return Cache::get(self::key($this->id), []) + [
            'status' => 'transcribing',
            'transcript' => null,
            'answer' => null,
            'error' => null,
            'speaks' => false,
        ];
    }

    /** @param array<string, mixed> $values */
    public function put(array $values): void
    {
        Cache::put(
            self::key($this->id),
            $values + $this->state(),
            now()->addMinutes(self::TTL_MINUTES),
        );
    }

    public function fail(string $message): void
    {
        $this->put(['status' => 'failed', 'error' => $message]);
    }

    /* ------------------------------- audio ------------------------------- */

    public function recordingPath(): string
    {
        return self::DIRECTORY.'/'.$this->id.'-asked.webm';
    }

    public function answerPath(): string
    {
        return self::DIRECTORY.'/'.$this->id.'-said.mp3';
    }

    public function storeRecording(string $audio): void
    {
        Storage::disk(self::DISK)->put($this->recordingPath(), $audio);
    }

    public function recording(): ?string
    {
        $disk = Storage::disk(self::DISK);

        return $disk->exists($this->recordingPath()) ? $disk->get($this->recordingPath()) : null;
    }

    /** The recording has served its purpose the moment it becomes words. */
    public function forgetRecording(): void
    {
        Storage::disk(self::DISK)->delete($this->recordingPath());
    }

    public function storeAnswerAudio(string $mp3): void
    {
        Storage::disk(self::DISK)->put($this->answerPath(), $mp3);

        $this->put(['speaks' => true]);
    }

    public function answerAudio(): ?string
    {
        $disk = Storage::disk(self::DISK);

        return $disk->exists($this->answerPath()) ? $disk->get($this->answerPath()) : null;
    }

    protected static function key(string $id): string
    {
        return 'familyhub:spoken:'.$id;
    }
}
