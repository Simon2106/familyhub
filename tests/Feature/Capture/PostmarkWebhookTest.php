<?php

namespace Tests\Feature\Capture;

use App\Jobs\ProcessCaptureJob;
use App\Models\Capture;
use App\Models\Household;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PostmarkWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'familyhub.postmark.inbound_secret' => 'inbound-secret',
            'familyhub.inbound_address' => 'ai@hub.example',
        ]);
        Household::factory()->create();
        Storage::fake('local');
        Queue::fake();
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'From' => 'office@school.example',
            'FromFull' => ['Email' => 'office@school.example'],
            'ToFull' => [['Email' => 'ai@hub.example']],
            'Subject' => 'Autumn term dates',
            'TextBody' => "Parents evening is on 15 September at 6pm.\nInset day 9 October.",
        ], $overrides);
    }

    #[Test]
    public function a_correct_secret_in_the_url_is_accepted(): void
    {
        $this->postJson('/webhooks/postmark/inbound-secret', $this->payload())
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $capture = Capture::firstOrFail();
        $this->assertSame('email', $capture->source);
        $this->assertSame('Autumn term dates', $capture->subject);
        $this->assertSame('office@school.example', $capture->sender);

        Queue::assertPushed(ProcessCaptureJob::class);
    }

    #[Test]
    public function basic_auth_is_accepted_as_an_alternative(): void
    {
        // Postmark offers a URL secret or basic auth; it signs nothing.
        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('postmark:inbound-secret')])
            ->postJson('/webhooks/postmark', $this->payload())
            ->assertOk();

        $this->assertSame(1, Capture::count());
    }

    #[Test]
    public function a_wrong_secret_is_refused(): void
    {
        $this->postJson('/webhooks/postmark/nope', $this->payload())->assertNotFound();

        $this->assertSame(0, Capture::count());
    }

    #[Test]
    public function no_secret_at_all_is_refused(): void
    {
        $this->postJson('/webhooks/postmark', $this->payload())->assertNotFound();

        $this->assertSame(0, Capture::count());
    }

    #[Test]
    public function it_refuses_rather_than_revealing_the_endpoint_exists(): void
    {
        // 404 not 401: an unauthenticated caller learns nothing.
        $this->postJson('/webhooks/postmark/wrong', $this->payload())->assertStatus(404);
    }

    #[Test]
    public function it_fails_loudly_when_no_secret_is_configured(): void
    {
        config(['familyhub.postmark.inbound_secret' => null]);

        $this->postJson('/webhooks/postmark/anything', $this->payload())->assertStatus(503);
    }

    #[Test]
    public function csrf_does_not_block_the_webhook(): void
    {
        // Postmark cannot present a token; the shared secret authenticates it.
        $this->post('/webhooks/postmark/inbound-secret', $this->payload())->assertOk();
    }

    #[Test]
    public function it_falls_back_to_the_html_part_when_there_is_no_text(): void
    {
        $this->postJson('/webhooks/postmark/inbound-secret', $this->payload([
            'TextBody' => '',
            'HtmlBody' => '<html><head><style>p{color:red}</style></head><body><p>Sports day is 12 June.</p><script>var x=1</script></body></html>',
        ]))->assertOk();

        $body = Capture::firstOrFail()->body_text;

        $this->assertStringContainsString('Sports day is 12 June.', $body);
        // Script and style content would otherwise become the bulk of the prompt.
        $this->assertStringNotContainsString('var x=1', $body);
        $this->assertStringNotContainsString('color:red', $body);
    }

    #[Test]
    public function it_stores_readable_attachments(): void
    {
        $this->postJson('/webhooks/postmark/inbound-secret', $this->payload([
            'Attachments' => [
                ['Name' => 'term-dates.pdf', 'ContentType' => 'application/pdf', 'Content' => base64_encode('%PDF-1.4 fake')],
                ['Name' => 'photo.jpg', 'ContentType' => 'image/jpeg; charset=binary', 'Content' => base64_encode('jpegbytes')],
            ],
        ]))->assertOk();

        $attachments = Capture::firstOrFail()->attachments;

        $this->assertCount(2, $attachments);
        $this->assertSame(['term-dates.pdf', 'photo.jpg'], $attachments->pluck('filename')->all());
        // The content type arrives with parameters on some senders.
        $this->assertSame('image/jpeg', $attachments->last()->mime);
        Storage::disk('local')->assertExists($attachments->first()->path);
    }

    #[Test]
    public function it_ignores_attachments_it_cannot_read(): void
    {
        $this->postJson('/webhooks/postmark/inbound-secret', $this->payload([
            'Attachments' => [
                ['Name' => 'signature.p7s', 'ContentType' => 'application/pkcs7-signature', 'Content' => base64_encode('junk')],
                ['Name' => 'dates.pdf', 'ContentType' => 'application/pdf', 'Content' => base64_encode('%PDF')],
            ],
        ]))->assertOk();

        $this->assertSame(['dates.pdf'], Capture::firstOrFail()->attachments->pluck('filename')->all());
    }

    #[Test]
    public function an_empty_email_is_ignored_with_a_200(): void
    {
        // A non-2xx would make Postmark retry a junk delivery forever.
        $this->postJson('/webhooks/postmark/inbound-secret', $this->payload(['TextBody' => '', 'HtmlBody' => '']))
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        $this->assertSame(0, Capture::count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_raw_payload_is_kept_for_a_re_run(): void
    {
        $this->postJson('/webhooks/postmark/inbound-secret', $this->payload())->assertOk();

        $this->assertStringContainsString('Autumn term dates', Capture::firstOrFail()->raw_payload);
    }
}
