<?php

namespace Tests\Feature\Speech;

use App\Jobs\AnswerSpokenQuestionJob;
use App\Models\Household;
use App\Services\Assistant\Contracts\Assistant;
use App\Services\Speech\Contracts\SpeechToText;
use App\Services\Speech\Contracts\TextToSpeech;
use App\Services\Speech\SpokenQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeAssistant;
use Tests\Support\FakeSpeechToText;
use Tests\Support\FakeTextToSpeech;
use Tests\TestCase;

/**
 * Fifteen seconds of kitchen, turned into an answer.
 *
 * Three calls deep and every one of them can fail on its own, so most of what
 * matters here is what the wall says when one does.
 */
class SpokenQuestionTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected FakeSpeechToText $ears;

    protected FakeAssistant $assistant;

    protected FakeTextToSpeech $voice;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->household = Household::factory()->create();

        $this->ears = new FakeSpeechToText;
        $this->assistant = new FakeAssistant;
        $this->voice = new FakeTextToSpeech;

        $this->app->instance(SpeechToText::class, $this->ears);
        $this->app->instance(Assistant::class, $this->assistant);
        $this->app->instance(TextToSpeech::class, $this->voice);
    }

    protected function ask(string $audio = 'RECORDING'): SpokenQuestion
    {
        $question = SpokenQuestion::start();
        $question->storeRecording($audio);

        // No arguments: the job resolves its own, so that a container failure
        // is caught rather than thrown past every catch in it.
        (new AnswerSpokenQuestionJob($question->id))->handle();

        return $question;
    }

    #[Test]
    public function a_recording_becomes_a_question_an_answer_and_a_voice(): void
    {
        $this->ears->heard = 'What is for tea?';
        $this->assistant->queue('Fish pie, from this week’s meal plan.', ['meals']);

        $state = $this->ask()->state();

        $this->assertSame('done', $state['status']);
        $this->assertSame('What is for tea?', $state['transcript']);
        $this->assertSame('Fish pie, from this week’s meal plan.', $state['answer']);
        $this->assertTrue($state['speaks']);

        // The assistant is the one on /app, unchanged and read-only.
        $this->assertSame('What is for tea?', $this->assistant->asked[0]['question']);
        $this->assertSame(['Fish pie, from this week’s meal plan.'], $this->voice->spoke);
    }

    #[Test]
    public function it_asks_whisper_for_english(): void
    {
        // Given the choice, Whisper will occasionally decide a mumbled English
        // sentence is Welsh.
        $this->ask();

        $this->assertSame('en', $this->ears->calls[0]['language']);
        $this->assertSame('RECORDING', $this->ears->calls[0]['audio']);
    }

    #[Test]
    public function the_recording_is_thrown_away_the_moment_it_becomes_words(): void
    {
        // Keeping it would turn a transcript-free feature into an archive of
        // the kitchen.
        $question = $this->ask();

        Storage::disk('local')->assertMissing($question->recordingPath());
    }

    #[Test]
    public function the_recording_is_thrown_away_even_when_it_could_not_be_read(): void
    {
        $this->ears->throw = 'The OpenAI key was refused.';

        $question = $this->ask();

        Storage::disk('local')->assertMissing($question->recordingPath());
        $this->assertSame('The OpenAI key was refused.', $question->state()['error']);
        $this->assertSame('failed', $question->state()['status']);
    }

    #[Test]
    public function nothing_said_is_said_so(): void
    {
        $this->ears->heard = '   ';

        $state = $this->ask()->state();

        $this->assertSame('failed', $state['status']);
        $this->assertSame('I didn\'t catch that.', $state['error']);
        $this->assertSame([], $this->assistant->asked, 'Nothing was said, so nothing was asked.');
    }

    #[Test]
    public function an_assistant_that_fails_says_why(): void
    {
        $this->assistant->throw = 'Claude declined to answer that.';

        $state = $this->ask()->state();

        $this->assertSame('failed', $state['status']);
        $this->assertSame('Claude declined to answer that.', $state['error']);
    }

    #[Test]
    public function a_muted_household_still_gets_its_answer(): void
    {
        $this->household->setWallSpeaks(false);
        $this->assistant->queue('Fish pie.');

        $state = $this->ask()->state();

        $this->assertSame('done', $state['status']);
        $this->assertSame('Fish pie.', $state['answer']);
        $this->assertFalse($state['speaks']);
        $this->assertSame([], $this->voice->spoke, 'Muted means it is never even asked to speak.');
    }

    #[Test]
    public function a_voice_that_will_not_work_costs_nothing_but_the_sound(): void
    {
        // An answer nobody reads aloud is still an answer on the screen.
        $this->voice->audio = null;
        $this->assistant->queue('Fish pie.');

        $state = $this->ask()->state();

        $this->assertSame('done', $state['status']);
        $this->assertSame('Fish pie.', $state['answer']);
        $this->assertFalse($state['speaks']);
    }

    #[Test]
    public function a_question_nobody_is_waiting_for_any_more_is_dropped(): void
    {
        // Ten minutes on, whoever asked has left the kitchen.
        (new AnswerSpokenQuestionJob('7c9e6679-7425-40de-944b-e07fc1f90ae7'))->handle();

        $this->assertSame([], $this->ears->calls);
    }

    #[Test]
    public function a_crash_still_leaves_something_on_the_wall(): void
    {
        // Otherwise the display spins until the thirty-second timeout.
        $question = SpokenQuestion::start();

        (new AnswerSpokenQuestionJob($question->id))->failed(new RuntimeException('boom'));

        $this->assertSame('failed', $question->state()['status']);
        $this->assertSame('Something went wrong answering that.', $question->state()['error']);
    }

    #[Test]
    public function an_answer_nobody_came_back_for_is_swept_up(): void
    {
        // The question expires from the cache on its own; the MP3 has to be
        // swept, or a kitchen's questions accumulate on disk for ever.
        $this->assistant->queue('Fish pie.');
        $question = $this->ask();

        Storage::disk('local')->assertExists($question->answerPath());

        $this->travel(2)->hours();
        $this->artisan('familyhub:prune-done')->assertSuccessful();

        Storage::disk('local')->assertMissing($question->answerPath());
    }

    #[Test]
    public function an_answer_still_being_played_is_left_alone(): void
    {
        $this->assistant->queue('Fish pie.');
        $question = $this->ask();

        $this->artisan('familyhub:prune-done')->assertSuccessful();

        Storage::disk('local')->assertExists($question->answerPath());
    }

    #[Test]
    public function a_question_is_forgotten_rather_than_kept(): void
    {
        // Nothing about this reaches the database: what a family asks their
        // kitchen is not a record anybody asked us to keep.
        $this->assistant->queue('Fish pie.');
        $question = $this->ask();

        $this->travel(SpokenQuestion::TTL_MINUTES + 1)->minutes();

        $this->assertNull(SpokenQuestion::find($question->id));
    }
}
