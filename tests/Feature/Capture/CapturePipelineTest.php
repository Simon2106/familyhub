<?php

namespace Tests\Feature\Capture;

use App\Jobs\ProcessCaptureJob;
use App\Models\Capture;
use App\Models\CaptureItem;
use App\Models\Household;
use App\Models\Member;
use App\Services\Capture\CaptureIntake;
use App\Services\Capture\Contracts\ItemExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeItemExtractor;
use Tests\TestCase;

class CapturePipelineTest extends TestCase
{
    use RefreshDatabase;

    protected FakeItemExtractor $extractor;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-07 09:00:00');
        Storage::fake('local');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);

        // The whole pipeline runs; only the API call is faked.
        $this->extractor = new FakeItemExtractor;
        $this->app->instance(ItemExtractor::class, $this->extractor);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function capture(array $attributes = []): Capture
    {
        return app(CaptureIntake::class)->create('text', array_merge([
            'household' => $this->household,
            'body_text' => 'Parents evening 15 September at 6pm.',
        ], $attributes), dispatch: false);
    }

    #[Test]
    public function processing_a_capture_creates_reviewable_items(): void
    {
        $this->extractor->queueItems([
            ['type' => 'event', 'title' => 'Parents evening', 'start' => '2026-09-15T18:00:00', 'end' => '2026-09-15T20:00:00', 'confidence' => 95],
            ['type' => 'event', 'title' => 'Inset day', 'start' => '2026-10-09', 'confidence' => 85],
        ], 'Autumn term dates.');

        $capture = $this->capture();

        dispatch_sync(new ProcessCaptureJob($capture));

        $capture->refresh();
        $this->assertSame('reviewing', $capture->status);
        $this->assertSame('Autumn term dates.', $capture->summary);
        $this->assertCount(2, $capture->items);
        $this->assertNotNull($capture->processed_at);
    }

    #[Test]
    public function nothing_found_is_finished_not_failed(): void
    {
        $this->extractor->queueItems([], 'No dates in this one.');

        $capture = $this->capture();
        dispatch_sync(new ProcessCaptureJob($capture));

        $this->assertSame('done', $capture->fresh()->status);
        $this->assertNull($capture->fresh()->error);
    }

    #[Test]
    public function a_failure_is_recorded_against_the_capture(): void
    {
        $this->extractor->throw = 'The API is having a moment.';

        $capture = $this->capture();
        $job = new ProcessCaptureJob($capture);

        try {
            dispatch_sync($job);
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        $capture->refresh();
        $this->assertSame('failed', $capture->status);
        $this->assertStringContainsString('having a moment', $capture->error);
    }

    #[Test]
    public function a_duplicate_delivery_does_not_double_the_items(): void
    {
        $this->extractor->queueItems([
            ['type' => 'event', 'title' => 'Parents evening', 'start' => '2026-09-15T18:00:00', 'confidence' => 95],
        ]);

        $capture = $this->capture();
        dispatch_sync(new ProcessCaptureJob($capture));
        dispatch_sync(new ProcessCaptureJob($capture->fresh()));

        $this->assertSame(1, CaptureItem::count());
    }

    #[Test]
    public function the_prompt_is_told_what_today_is_and_who_lives_here(): void
    {
        Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Sienna']);
        $this->extractor->queueItems([]);

        dispatch_sync(new ProcessCaptureJob($this->capture()));

        // The extractor is handed a capture with its household and members
        // loaded, which is what the prompt is built from.
        $seen = $this->extractor->sawCaptures[0];
        $this->assertTrue($seen->relationLoaded('household'));
        $this->assertSame('Sienna', $seen->household->members->first()->name);
    }

    #[Test]
    public function a_capture_whose_only_attachment_was_unreadable_fails_rather_than_reporting_nothing(): void
    {
        // "Nothing found" would be indistinguishable from an email that had no
        // dates in it, and the household would never know the PDF went unread.
        $this->extractor->throw = '1 attachment could not be read because it was too large: huge.pdf.';

        $capture = $this->capture(['body_text' => null]);
        $job = new ProcessCaptureJob($capture);

        try {
            dispatch_sync($job);
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        $capture->refresh();
        $this->assertSame('failed', $capture->status);
        $this->assertStringContainsString('huge.pdf', $capture->error);
    }

    #[Test]
    public function an_upload_becomes_a_capture_with_its_file(): void
    {
        Queue::fake();

        $capture = app(CaptureIntake::class)->create('photo', [
            'household' => $this->household,
            'subject' => 'letter.jpg',
        ], [UploadedFile::fake()->image('letter.jpg')]);

        $this->assertSame('photo', $capture->source);
        $this->assertCount(1, $capture->attachments);
        Storage::disk('local')->assertExists($capture->attachments->first()->path);
        Queue::assertPushed(ProcessCaptureJob::class);
    }

    #[Test]
    public function pasted_text_becomes_a_capture(): void
    {
        Queue::fake();

        $capture = app(CaptureIntake::class)->create('text', [
            'household' => $this->household,
            'body_text' => 'Swimming gala on the 12th.',
        ]);

        $this->assertSame('text', $capture->source);
        $this->assertSame('Swimming gala on the 12th.', $capture->body_text);
    }

    #[Test]
    public function captures_run_on_their_own_queue(): void
    {
        Queue::fake();

        ProcessCaptureJob::dispatch($this->capture());

        // Horizon gives this queue priority: someone is waiting on the inbox.
        Queue::assertPushedOn('capture', ProcessCaptureJob::class);
    }

    #[Test]
    public function the_command_queues_waiting_captures(): void
    {
        Queue::fake();

        $this->capture();
        $this->capture();

        $this->artisan('capture:process')->assertSuccessful();

        Queue::assertPushed(ProcessCaptureJob::class, 2);
    }

    #[Test]
    public function the_command_leaves_failed_captures_alone_unless_asked(): void
    {
        Queue::fake();

        Capture::factory()->failed()->create(['household_id' => $this->household->id]);

        $this->artisan('capture:process')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('capture:process', ['--retry-failed' => true])->assertSuccessful();
        Queue::assertPushed(ProcessCaptureJob::class, 1);
    }
}
