<?php

namespace Tests\Feature\Capture;

use App\Models\Capture;
use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShareTargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        $household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $household->id]));
    }

    #[Test]
    public function the_manifest_declares_a_share_target(): void
    {
        $manifest = $this->get('/manifest.webmanifest')->assertOk()->json();

        $this->assertSame(route('share-target'), $manifest['share_target']['action']);
        $this->assertSame('POST', $manifest['share_target']['method']);
        $this->assertSame('multipart/form-data', $manifest['share_target']['enctype']);
        $this->assertContains('application/pdf', $manifest['share_target']['params']['files'][0]['accept']);
    }

    #[Test]
    public function sharing_text_creates_a_capture(): void
    {
        $this->post('/app/share', ['title' => 'Term dates', 'text' => 'Inset day on 9 October.'])
            ->assertRedirect(route('review'));

        $capture = Capture::firstOrFail();
        $this->assertSame('share', $capture->source);
        $this->assertSame('Term dates', $capture->subject);
        $this->assertStringContainsString('9 October', $capture->body_text);
    }

    #[Test]
    public function sharing_a_file_creates_a_capture_with_it(): void
    {
        $this->post('/app/share', [
            'title' => 'Newsletter',
            'files' => [UploadedFile::fake()->create('newsletter.pdf', 100, 'application/pdf')],
        ])->assertRedirect(route('review'));

        $this->assertCount(1, Capture::firstOrFail()->attachments);
    }

    #[Test]
    public function sharing_a_bare_link_fetches_the_page(): void
    {
        Http::fake(['school.example/*' => Http::response('<html><head><title>Term dates</title></head><body><p>Inset day 9 October.</p></body></html>')]);

        $this->post('/app/share', ['url' => 'https://school.example/newsletter'])
            ->assertRedirect(route('review'));

        $capture = Capture::firstOrFail();
        $this->assertSame('url', $capture->source);
        $this->assertSame('Term dates', $capture->subject);
        $this->assertStringContainsString('Inset day 9 October.', $capture->body_text);
    }

    #[Test]
    public function a_link_that_arrives_as_text_is_still_recognised(): void
    {
        // Several iOS apps put the URL in `text` rather than `url`.
        Http::fake(['school.example/*' => Http::response('<html><title>Newsletter</title><body>Sports day 12 June.</body></html>')]);

        $this->post('/app/share', ['text' => 'https://school.example/news'])
            ->assertRedirect(route('review'));

        $this->assertSame('url', Capture::firstOrFail()->source);
    }

    #[Test]
    public function a_link_alongside_text_is_kept_as_context_not_fetched(): void
    {
        Http::fake();

        $this->post('/app/share', ['text' => 'Look at this', 'url' => 'https://school.example/news'])
            ->assertRedirect(route('review'));

        $capture = Capture::firstOrFail();
        $this->assertSame('share', $capture->source);
        $this->assertStringContainsString('https://school.example/news', $capture->body_text);
        Http::assertNothingSent();
    }

    #[Test]
    public function an_empty_share_says_so(): void
    {
        $this->post('/app/share', [])
            ->assertRedirect(route('review'))
            ->assertSessionHas('capture-error');

        $this->assertSame(0, Capture::count());
    }

    #[Test]
    public function an_unreachable_link_reports_rather_than_throwing(): void
    {
        Http::fake(['school.example/*' => Http::response('nope', 500)]);

        $this->post('/app/share', ['url' => 'https://school.example/news'])
            ->assertRedirect(route('review'))
            ->assertSessionHas('capture-error');
    }

    #[Test]
    public function sharing_requires_being_signed_in(): void
    {
        auth()->logout();

        $this->post('/app/share', ['text' => 'something'])->assertRedirect('/login');
    }
}
