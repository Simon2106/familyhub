<?php

namespace Tests\Feature\Speech;

use App\Services\Speech\OpenAiSpeechToText;
use App\Services\Speech\OpenAiTextToSpeech;
use App\Services\Speech\SpeechException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** What actually goes to OpenAI, and what comes back when it does not work. */
class OpenAiSpeechTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'familyhub.openai.key' => 'sk-test',
            'familyhub.openai.stt_model' => 'whisper-1',
            'familyhub.openai.tts_model' => 'tts-1',
            'familyhub.openai.voice' => 'fable',
            'familyhub.openai.timeout' => 45,
        ]);
    }

    /* ------------------------------ listening ---------------------------- */

    #[Test]
    public function a_recording_is_sent_as_a_file_in_english(): void
    {
        Http::fake([OpenAiSpeechToText::ENDPOINT => Http::response(['text' => ' What is for tea? '])]);

        $said = app(OpenAiSpeechToText::class)->transcribe('AUDIO-BYTES', 'question.webm');

        $this->assertSame('What is for tea?', $said, 'Trimmed, because the caller compares against empty.');

        Http::assertSent(function ($request) {
            $fields = collect($request->data())->keyBy('name');

            return $request->hasHeader('Authorization', 'Bearer sk-test')
                && $request->isMultipart()
                && $fields['model']['contents'] === 'whisper-1'
                // Told, not guessed: Whisper will otherwise occasionally decide
                // a mumbled English sentence is Welsh.
                && $fields['language']['contents'] === 'en'
                && $fields['file']['filename'] === 'question.webm';
        });
    }

    #[Test]
    public function an_unset_key_is_a_setup_step_not_a_fault(): void
    {
        config(['familyhub.openai.key' => null]);

        $this->assertFalse(app(OpenAiSpeechToText::class)->isConfigured());

        $this->expectExceptionMessage('Listening is not set up yet');

        app(OpenAiSpeechToText::class)->transcribe('AUDIO');
    }

    #[Test]
    public function every_failure_comes_back_as_a_sentence_for_a_wall(): void
    {
        // A kiosk has no console and nobody standing at it who wants a status
        // code.
        // Driven from a variable read at request time, because Http::fake()
        // MERGES stubs rather than replacing them — faked in a loop, the first
        // status keeps answering for all of them.
        $status = 200;

        // By reference, not an arrow function: those capture by value, so the
        // stub would answer 200 for ever however the loop moved on.
        Http::fake([OpenAiSpeechToText::ENDPOINT => function () use (&$status) {
            return Http::response([], $status);
        }]);

        foreach ([
            401 => 'The OpenAI key was refused.',
            429 => 'Too many requests just now — try again in a moment.',
            503 => 'OpenAI is having trouble. Try again in a moment.',
            400 => 'I couldn\'t make sense of that recording.',
        ] as $code => $expected) {
            $status = $code;

            try {
                app(OpenAiSpeechToText::class)->transcribe('AUDIO');
                $this->fail('A '.$code.' should have been refused.');
            } catch (SpeechException $e) {
                $this->assertSame($expected, $e->getMessage());
            }
        }
    }

    #[Test]
    public function an_unreachable_internet_says_so(): void
    {
        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->expectExceptionMessage('couldn\'t reach the internet');

        app(OpenAiSpeechToText::class)->transcribe('AUDIO');
    }

    #[Test]
    public function an_empty_recording_is_not_sent_at_all(): void
    {
        Http::fake();

        try {
            app(OpenAiSpeechToText::class)->transcribe('');
        } catch (SpeechException) {
        }

        Http::assertNothingSent();
    }

    /* ------------------------------- speaking ---------------------------- */

    #[Test]
    public function the_answer_is_asked_for_as_mp3_in_a_british_voice(): void
    {
        Http::fake([OpenAiTextToSpeech::ENDPOINT => Http::response('MP3-BYTES')]);

        $this->assertSame('MP3-BYTES', app(OpenAiTextToSpeech::class)->speak('Fish pie.'));

        Http::assertSent(fn ($request) => $request['model'] === 'tts-1'
            && $request['voice'] === 'fable'
            && $request['input'] === 'Fish pie.'
            && $request['response_format'] === 'mp3');
    }

    #[Test]
    public function a_voice_that_will_not_work_stays_quiet_rather_than_throwing(): void
    {
        // An answer nobody reads aloud is still an answer on the screen.
        Http::fake([OpenAiTextToSpeech::ENDPOINT => Http::response([], 500)]);

        $this->assertNull(app(OpenAiTextToSpeech::class)->speak('Fish pie.'));

        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->assertNull(app(OpenAiTextToSpeech::class)->speak('Fish pie.'));
    }

    #[Test]
    public function nothing_worth_saying_is_not_sent(): void
    {
        Http::fake();

        $this->assertNull(app(OpenAiTextToSpeech::class)->speak('   '));

        config(['familyhub.openai.key' => null]);
        $this->assertNull(app(OpenAiTextToSpeech::class)->speak('Fish pie.'));

        Http::assertNothingSent();
    }

    #[Test]
    public function an_answer_that_runs_away_with_itself_is_cut_short(): void
    {
        // Charged by the character, and no kitchen wants a five-minute reply.
        Http::fake([OpenAiTextToSpeech::ENDPOINT => Http::response('MP3')]);

        app(OpenAiTextToSpeech::class)->speak(str_repeat('a', 5000));

        Http::assertSent(fn ($request) => mb_strlen($request['input']) === OpenAiTextToSpeech::MAX_CHARACTERS);
    }
}
