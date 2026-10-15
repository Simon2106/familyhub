<?php

namespace Tests\Feature\HomeAssistant;

use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use App\Services\Announce\AnnouncePhrase;
use App\Services\Announce\Announcer;
use App\Services\HomeAssistant\Client;
use App\Services\HomeAssistant\HomeAssistant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Shouting up the stairs, without the stairs. */
class AnnounceTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected bool $haIsDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'familyhub.homeassistant.url' => 'http://ha.test',
            'familyhub.homeassistant.token' => 'token',
            'familyhub.homeassistant.cache_seconds' => 0,
        ]);

        Cache::flush();

        $this->household = Household::factory()->create();
        User::factory()->create(['household_id' => $this->household->id]);

        Http::fake([
            '*/api/services/*' => fn () => $this->haIsDown
                ? Http::response('nope', 500)
                : Http::response([]),
            '*/api/states' => Http::response([]),
        ]);
    }

    protected function announcer(): Announcer
    {
        return new Announcer(new HomeAssistant(new Client));
    }

    protected function setUpAlexa(): void
    {
        $this->household->setAnnounceConfig('notify.alexa_media', [
            'Kitchen Echo',
            'Landing Echo',
        ]);
    }

    /* ------------------------------ saying ------------------------------ */

    #[Test]
    public function an_announcement_reaches_the_speakers(): void
    {
        $this->setUpAlexa();

        $result = $this->announcer()->announce($this->household->fresh(), "Dinner's ready");

        $this->assertTrue($result['sent']);
        $this->assertTrue($result['spoken']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/services/notify/alexa_media')
                && $request['message'] === "Dinner's ready"
                && $request['target'] === ['Kitchen Echo', 'Landing Echo']
                && $request['data']['type'] === 'announce';
        });
    }

    #[Test]
    public function without_an_alexa_it_still_goes_on_the_wall_and_says_why(): void
    {
        $result = $this->announcer()->announce($this->household, 'Bedtime');

        $this->assertTrue($result['sent'], 'The banner is the point of the feature too.');
        $this->assertFalse($result['spoken']);
        $this->assertStringContainsString('No Alexa is set up', (string) $result['reason']);
    }

    #[Test]
    public function a_speaker_that_will_not_answer_is_not_a_broken_wall(): void
    {
        $this->setUpAlexa();

        $this->haIsDown = true;

        $result = $this->announcer()->announce($this->household->fresh(), 'Bedtime');

        $this->assertTrue($result['sent']);
        $this->assertFalse($result['spoken']);
    }

    #[Test]
    public function it_remembers_who_said_it(): void
    {
        $member = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Jenna']);

        $this->assertSame('Jenna', $this->announcer()->announce($this->household, 'Bedtime', $member)['by']);

        Cache::forget(Announcer::RATE_KEY);

        $this->assertSame('Wall', $this->announcer()->announce($this->household, 'Bedtime')['by']);
    }

    /* ---------------------------- rate limiting -------------------------- */

    #[Test]
    public function a_button_at_a_childs_height_gets_pressed_eleven_times(): void
    {
        $at = CarbonImmutable::parse('2026-10-15 18:00:00');

        $this->assertTrue($this->announcer()->announce($this->household, 'Bedtime', null, $at)['sent']);

        $again = $this->announcer()->announce($this->household, 'Bedtime', null, $at->addSeconds(3));

        $this->assertFalse($again['sent']);
        $this->assertStringContainsString('one at a time', (string) $again['reason']);

        // And it lets go.
        $this->assertTrue(
            $this->announcer()->announce($this->household, 'Bedtime', null, $at->addSeconds(11))['sent'],
        );
    }

    #[Test]
    public function nothing_at_all_is_not_announced(): void
    {
        $this->assertFalse($this->announcer()->announce($this->household, '   ')['sent']);
    }

    #[Test]
    public function the_last_fifty_are_kept_for_admin(): void
    {
        $at = CarbonImmutable::parse('2026-10-15 18:00:00');

        foreach (range(1, 4) as $n) {
            $this->announcer()->announce($this->household, "Message {$n}", null, $at->addSeconds($n * 11));
        }

        $log = $this->announcer()->recent();

        $this->assertCount(4, $log);
        $this->assertSame('Message 4', $log[0]['message'], 'Newest first.');
    }

    /* ----------------------------- presets ------------------------------- */

    #[Test]
    public function the_four_presets_can_be_changed_and_fall_back_when_emptied(): void
    {
        $this->assertSame(Household::ANNOUNCE_DEFAULTS, $this->household->announcePresets());

        $this->household->setAnnouncePresets(['Tea is up', '', 'Shoes on', '  ']);

        $this->assertSame(['Tea is up', 'Shoes on'], $this->household->fresh()->announcePresets());

        $this->household->setAnnouncePresets([]);

        $this->assertSame(Household::ANNOUNCE_DEFAULTS, $this->household->fresh()->announcePresets());
    }

    /* ------------------------------ hearing ------------------------------ */

    protected function phrase(): AnnouncePhrase
    {
        return app(AnnouncePhrase::class);
    }

    #[Test]
    public function it_hears_an_announcement_in_a_sentence(): void
    {
        $this->assertTrue($this->phrase()->looksLikeOne("announce dinner's ready"));
        $this->assertSame("Dinner's ready", $this->phrase()->message("announce dinner's ready"));

        $this->assertSame('We are leaving in five minutes', $this->phrase()->message('tell everyone that we are leaving in five minutes'));
    }

    #[Test]
    public function an_ordinary_question_is_left_to_the_assistant(): void
    {
        // Announcing a question at the whole house is a far worse failure
        // than not recognising one.
        foreach (["what's for tea", 'when is the dentist', 'what did Jenna announce'] as $said) {
            $this->assertFalse($this->phrase()->looksLikeOne($said), $said);
        }
    }

    #[Test]
    public function somebody_who_stopped_mid_sentence_announces_nothing(): void
    {
        // The word on its own is not a request to say anything, so it goes
        // to the assistant rather than shouting an empty room at the house.
        $this->assertFalse($this->phrase()->looksLikeOne('announce'));

        // And a trigger with nothing after it yields no message either way.
        $this->assertNull($this->phrase()->message('announce that   '));
    }

    #[Test]
    public function the_wall_component_puts_a_banner_up(): void
    {
        Livewire::test('display.announce')
            ->call('say', "Dinner's ready")
            ->assertSet('banner.message', "Dinner's ready")
            ->assertSee("Dinner's ready")
            ->call('dismiss')
            ->assertSet('banner', null);
    }

    #[Test]
    public function the_wall_offers_the_presets_rather_than_a_keyboard(): void
    {
        Livewire::test('display.announce')
            ->call('open')
            ->assertSee("Dinner's ready")
            ->assertSee('Bedtime');
    }
}
