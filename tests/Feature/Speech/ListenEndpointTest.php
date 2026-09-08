<?php

namespace Tests\Feature\Speech;

use App\Http\Middleware\EnsureDisplayToken;
use App\Jobs\AnswerSpokenQuestionJob;
use App\Models\Household;
use App\Services\Speech\Contracts\SpeechToText;
use App\Services\Speech\SpokenQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeSpeechToText;
use Tests\TestCase;

/** The three endpoints behind the wall's microphone. */
class ListenEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'wall-display-token-for-tests';

    protected FakeSpeechToText $ears;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Household::factory()->create();

        config(['familyhub.display.token' => self::TOKEN]);

        $this->ears = new FakeSpeechToText;
        $this->app->instance(SpeechToText::class, $this->ears);
    }

    /**
     * A device already paired, as the wall is.
     *
     * withCredentials because Laravel's JSON helpers send no cookies without
     * it — the same trap in reverse as the display's own fetch calls, which
     * have to ask for the pairing cookie explicitly.
     */
    protected function paired(): static
    {
        return $this->withCookie(EnsureDisplayToken::COOKIE, self::TOKEN)->withCredentials();
    }

    protected function recording(int $kilobytes = 20): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'question.webm',
            str_repeat('a', $kilobytes * 1024),
        );
    }

    /* ------------------------------- asking ------------------------------ */

    #[Test]
    public function an_unpaired_device_cannot_ask_anything(): void
    {
        // The microphone is behind the same gate as the display itself.
        $this->postJson('/display/listen', ['audio' => $this->recording()])->assertForbidden();
        $this->getJson('/display/listen/7c9e6679-7425-40de-944b-e07fc1f90ae7')->assertForbidden();
        $this->get('/display/speech/7c9e6679-7425-40de-944b-e07fc1f90ae7')->assertForbidden();
    }

    #[Test]
    public function a_recording_is_taken_and_queued(): void
    {
        Queue::fake();

        $response = $this->paired()->post('/display/listen', ['audio' => $this->recording()]);

        $response->assertOk();
        $id = $response->json('id');

        $this->assertNotNull(SpokenQuestion::find($id));
        Queue::assertPushed(AnswerSpokenQuestionJob::class, fn ($job) => $job->questionId === $id);
    }

    #[Test]
    public function the_answering_happens_off_the_request(): void
    {
        // A POST that waited for transcribe, ask and speak would look to the
        // kiosk exactly like a crash.
        Queue::fake();

        $this->paired()->post('/display/listen', ['audio' => $this->recording()])->assertOk();

        $this->assertSame([], $this->ears->calls);
    }

    #[Test]
    public function a_missing_key_is_a_sentence_rather_than_a_server_error(): void
    {
        // A kiosk with no keyboard is the worst possible place to meet a 500.
        $this->ears->configured = false;

        $this->paired()->postJson('/display/listen', ['audio' => $this->recording()])
            ->assertStatus(503)
            ->assertJsonPath('error', 'Listening is not set up yet — no OpenAI key.');
    }

    #[Test]
    public function a_recording_is_required_and_bounded(): void
    {
        config(['familyhub.openai.max_upload_kb' => 100]);

        $this->paired()->postJson('/display/listen', [])->assertJsonValidationErrors('audio');
        $this->paired()->postJson('/display/listen', ['audio' => $this->recording(400)])
            ->assertJsonValidationErrors('audio');
    }

    /* ------------------------------ watching ----------------------------- */

    #[Test]
    public function the_display_can_watch_a_question_being_answered(): void
    {
        $question = SpokenQuestion::start();
        $question->put(['status' => 'thinking', 'transcript' => 'what is for tea']);

        $this->paired()->getJson('/display/listen/'.$question->id)
            ->assertOk()
            ->assertJsonPath('status', 'thinking')
            ->assertJsonPath('transcript', 'what is for tea');
    }

    #[Test]
    public function a_question_that_has_been_forgotten_says_so(): void
    {
        $this->paired()->getJson('/display/listen/7c9e6679-7425-40de-944b-e07fc1f90ae7')
            ->assertNotFound()
            ->assertJsonPath('error', 'That question has been forgotten.');
    }

    #[Test]
    public function an_id_that_is_not_an_id_is_not_looked_up(): void
    {
        $this->paired()->getJson('/display/listen/..%2F..%2Fetc')->assertNotFound();
        $this->assertNull(SpokenQuestion::find('../../etc/passwd'));
    }

    /* ------------------------------ speaking ----------------------------- */

    #[Test]
    public function the_answer_can_be_played(): void
    {
        $question = SpokenQuestion::start();
        $question->storeAnswerAudio('MP3-BYTES');

        $response = $this->paired()->get('/display/speech/'.$question->id);

        $response->assertOk();
        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('MP3-BYTES', $response->getContent());
    }

    #[Test]
    public function a_silent_answer_has_nothing_to_play(): void
    {
        $question = SpokenQuestion::start();

        $this->paired()->get('/display/speech/'.$question->id)->assertNotFound();
    }
}
