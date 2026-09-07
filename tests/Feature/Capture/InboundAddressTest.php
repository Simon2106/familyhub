<?php

namespace Tests\Feature\Capture;

use App\Models\Capture;
use App\Models\Household;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The inbound stream receives whatever is sent to the domain; only mail
 * actually addressed to the capture address should become a capture.
 */
class InboundAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'familyhub.postmark.inbound_secret' => 'inbound-secret',
            'familyhub.inbound_address' => 'ai@hub.thewills.uk',
        ]);

        Household::factory()->create();
        Storage::fake('local');
        Queue::fake();
    }

    protected function deliver(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/webhooks/postmark/inbound-secret', array_merge([
            'From' => 'office@school.example',
            'Subject' => 'Autumn term dates',
            'TextBody' => 'Parents evening is on 15 September.',
        ], $overrides));
    }

    #[Test]
    public function mail_to_the_capture_address_is_accepted(): void
    {
        $this->deliver(['ToFull' => [['Email' => 'ai@hub.thewills.uk']]])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $this->assertSame(1, Capture::count());
    }

    #[Test]
    public function mail_addressed_elsewhere_is_acknowledged_and_ignored(): void
    {
        $response = $this->deliver(['ToFull' => [['Email' => 'simon@hub.thewills.uk']]]);

        // 200, or Postmark retries a message that will never be wanted.
        $response->assertOk()->assertJsonPath('status', 'ignored')->assertJsonPath('reason', 'recipient');

        $this->assertSame(0, Capture::count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_ignored_message_is_logged_with_what_it_was_addressed_to(): void
    {
        Log::spy();

        $this->deliver(['ToFull' => [['Email' => 'spam@hub.thewills.uk']]])->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => $message === 'Ignoring inbound email addressed elsewhere'
                && in_array('spam@hub.thewills.uk', $context['recipients'], true)
                && $context['expected'] === 'ai@hub.thewills.uk')
            ->once();
    }

    #[Test]
    public function a_forwarded_message_is_matched_on_the_envelope_recipient(): void
    {
        // Auto-forwarded mail still carries the school's address in To; only
        // OriginalRecipient says where it actually landed.
        $this->deliver([
            'To' => 'parents@school.example',
            'ToFull' => [['Email' => 'parents@school.example']],
            'OriginalRecipient' => 'ai@hub.thewills.uk',
        ])->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertSame(1, Capture::count());
    }

    #[Test]
    public function being_cc_d_counts(): void
    {
        $this->deliver([
            'ToFull' => [['Email' => 'simon@example.com']],
            'CcFull' => [['Email' => 'ai@hub.thewills.uk']],
        ])->assertOk()->assertJsonPath('status', 'accepted');
    }

    #[Test]
    public function a_header_with_a_display_name_is_parsed(): void
    {
        $this->deliver(['To' => 'FamilyHub <ai@hub.thewills.uk>, someone@else.com'])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');
    }

    #[Test]
    public function the_match_ignores_case(): void
    {
        $this->deliver(['ToFull' => [['Email' => 'AI@Hub.TheWills.UK']]])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');
    }

    #[Test]
    public function a_plus_tag_still_reaches_the_capture_address(): void
    {
        // So a source can be tagged without configuring another address.
        $this->deliver(['ToFull' => [['Email' => 'ai+sandygate@hub.thewills.uk']]])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $this->assertSame(1, Capture::count());
    }

    #[Test]
    public function a_similar_looking_address_is_not_accepted(): void
    {
        foreach (['ai@hub.thewills.uk.evil.com', 'notai@hub.thewills.uk', 'ai@thewills.uk'] as $address) {
            Capture::query()->delete();

            $this->deliver(['ToFull' => [['Email' => $address]]])
                ->assertOk()
                ->assertJsonPath('status', 'ignored');

            $this->assertSame(0, Capture::count(), "{$address} should not have been accepted.");
        }
    }

    #[Test]
    public function an_unconfigured_address_accepts_anything(): void
    {
        // How this behaved before the address existed; a missing setting must
        // not silently stop inbound mail.
        config(['familyhub.inbound_address' => null]);

        $this->deliver(['ToFull' => [['Email' => 'anyone@anywhere.example']]])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');
    }

    #[Test]
    public function the_recipient_check_runs_before_anything_is_stored(): void
    {
        $this->deliver([
            'ToFull' => [['Email' => 'spam@hub.thewills.uk']],
            'Attachments' => [
                ['Name' => 'payload.pdf', 'ContentType' => 'application/pdf', 'Content' => base64_encode('%PDF')],
            ],
        ])->assertOk()->assertJsonPath('status', 'ignored');

        // Nothing written to disk for a message that was never wanted.
        $this->assertSame(0, Capture::count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }
}
