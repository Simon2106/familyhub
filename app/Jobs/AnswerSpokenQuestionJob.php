<?php

namespace App\Jobs;

use App\Models\Household;
use App\Services\Assistant\Contracts\Assistant;
use App\Services\Speech\Contracts\SpeechToText;
use App\Services\Speech\Contracts\TextToSpeech;
use App\Services\Speech\SpeechException;
use App\Services\Speech\SpokenQuestion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns fifteen seconds of kitchen into an answer.
 *
 * Queued because it is three calls deep — transcribe, ask, speak — and a wall
 * that blocks an HTTP request for eight seconds is a wall that appears to have
 * crashed. The display watches the question instead, and the states it shows
 * are the ones set here.
 *
 * One attempt, on purpose. Somebody is standing in front of the screen waiting;
 * a retry two minutes later answers a question nobody is still asking, and the
 * second answer would be spoken into an empty room.
 */
class AnswerSpokenQuestionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $questionId)
    {
        $this->onQueue('capture');
    }

    public function handle(): void
    {
        $question = SpokenQuestion::find($this->questionId);

        if (! $question) {
            // The question expired, which means nobody is waiting for it.
            return;
        }

        // Resolved here rather than injected into handle(): method injection
        // happens before this method's body, so a container that cannot build
        // one of these — a missing API key, most likely — would throw past
        // every catch below and leave the wall spinning at a dialog that never
        // says anything.
        try {
            $ears = app(SpeechToText::class);
            $assistant = app(Assistant::class);
            $voice = app(TextToSpeech::class);
        } catch (Throwable $e) {
            report($e);
            $question->fail('Answering is not set up yet on the server.');
            $question->forgetRecording();

            return;
        }

        $household = Household::current();

        try {
            $said = $ears->transcribe(
                $question->recording() ?? '',
                'speech.webm',
                (string) config('familyhub.openai.language'),
            );
        } catch (SpeechException $e) {
            $question->fail($e->getMessage());
            $question->forgetRecording();

            return;
        } finally {
            // Whatever happened, the recording has done its job. Keeping it
            // would turn a transcript-free feature into an archive of the
            // kitchen.
            $question->forgetRecording();
        }

        // Trimmed here as well as in the reader: "nothing was said" is the
        // most common outcome of all — a wall in a kitchen hears the kettle —
        // and it must not depend on every implementation being tidy.
        $said = trim($said);

        if ($said === '') {
            $question->fail('I didn\'t catch that.');

            return;
        }

        $question->put(['status' => 'thinking', 'transcript' => $said]);

        try {
            $answer = $assistant->ask($said, $household);
        } catch (Throwable $e) {
            report($e);
            $question->fail($e->getMessage());

            return;
        }

        $question->put(['status' => 'speaking', 'answer' => $answer->text]);

        // Silence is a supported outcome: muted in /admin, no key, or the
        // request simply did not work. The answer is on the screen either way.
        if ($household->wallSpeaks()) {
            if ($mp3 = $voice->speak($answer->text)) {
                $question->storeAnswerAudio($mp3);
            }
        }

        $question->put(['status' => 'done']);

        Log::info('The wall answered out loud', [
            'heard' => mb_strlen($said),
            'used' => $answer->used,
        ]);
    }

    /** A crash still has to leave something on the wall besides a spinner. */
    public function failed(?Throwable $e): void
    {
        SpokenQuestion::find($this->questionId)?->fail('Something went wrong answering that.');
    }
}
