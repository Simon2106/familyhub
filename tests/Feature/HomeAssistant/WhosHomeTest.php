<?php

namespace Tests\Feature\HomeAssistant;

use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use App\Services\HomeAssistant\Client;
use App\Services\HomeAssistant\HomeAssistant;
use App\Services\HomeAssistant\WhosHome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Who is in the house, and what the wall does about it. */
class WhosHomeTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $mum;

    protected Member $dad;

    protected Member $child;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'familyhub.homeassistant.url' => 'http://ha.test',
            'familyhub.homeassistant.token' => 'token',
            'familyhub.homeassistant.cache_seconds' => 0,
        ]);

        $this->household = Household::factory()->create();
        User::factory()->create(['household_id' => $this->household->id]);

        $this->mum = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Jenna', 'sort_order' => 1]);
        $this->dad = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon', 'sort_order' => 2]);
        $this->child = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Ellie', 'is_child' => true, 'sort_order' => 3]);

        $this->household->setPresenceEntities([
            $this->mum->id => 'person.jenna',
            $this->dad->id => 'person.simon',
            $this->child->id => 'device_tracker.ellie_phone',
        ]);
    }

    /** What Home Assistant is currently saying. */
    protected array $entities = [];

    /**
     * Stand in for Home Assistant.
     *
     * The states live in a property and the stub is registered once, because
     * Http::fake() MERGES its stubs rather than replacing them and the first
     * match wins — so calling it again to change what the house is doing
     * would quietly keep answering with the old states, and a test about
     * somebody arriving would pass whatever the code did.
     *
     * @param  array<string, string>  $states
     */
    protected function haSays(array $states): void
    {
        $this->entities = $states;

        Cache::forget('ha:states');

        Http::fake(['*/api/states' => fn () => Http::response(
            collect($this->entities)->map(fn (string $state, string $id) => [
                'entity_id' => $id,
                'state' => $state,
                'attributes' => [],
            ])->values()->all()
        )]);
    }

    /** A fresh one each time: the driver memoises states for one render. */
    protected function board(): WhosHome
    {
        Cache::forget('ha:states');

        return new WhosHome(new HomeAssistant(
            new Client
        ));
    }

    #[Test]
    public function it_says_who_is_in_and_where_the_others_are(): void
    {
        $this->haSays([
            'person.jenna' => 'home',
            'person.simon' => 'work',
            'device_tracker.ellie_phone' => 'school',
        ]);

        $home = $this->board()->home($this->household);
        $away = $this->board()->away($this->household);

        $this->assertSame(['Jenna'], $home->pluck('name')->all());
        $this->assertSame(['Simon', 'Ellie'], $away->pluck('member.name')->all());
        $this->assertSame(['work', 'school'], $away->pluck('zone')->all());
    }

    #[Test]
    public function plain_not_home_gets_no_zone_rather_than_a_made_up_one(): void
    {
        $this->haSays(['person.jenna' => 'not_home', 'person.simon' => 'home', 'device_tracker.ellie_phone' => 'home']);

        $this->assertNull($this->board()->away($this->household)->first()['zone']);
    }

    #[Test]
    public function a_phone_that_drops_wifi_for_a_moment_does_not_empty_the_house(): void
    {
        // The whole reason the grace exists: a display that reshuffled every
        // time a phone lost the back bedroom is a display somebody turns off.
        $this->haSays(['person.jenna' => 'home', 'person.simon' => 'home', 'device_tracker.ellie_phone' => 'home']);

        $at = CarbonImmutable::parse('2026-10-15 18:00:00');

        $this->assertCount(3, $this->board()->home($this->household, $at));

        $this->haSays(['person.jenna' => 'not_home', 'person.simon' => 'home', 'device_tracker.ellie_phone' => 'home']);

        // A minute later she is still in, as far as the wall is concerned.
        $this->assertCount(3, $this->board()->home($this->household, $at->addMinute()));

        // Four minutes later she is not.
        $this->assertSame(
            ['Simon', 'Ellie'],
            $this->board()->home($this->household, $at->addMinutes(4))->pluck('name')->all(),
        );
    }

    #[Test]
    public function arriving_is_immediate(): void
    {
        // Somebody walking in is the event the wall exists to notice.
        $this->haSays(['person.jenna' => 'not_home', 'person.simon' => 'not_home', 'device_tracker.ellie_phone' => 'not_home']);

        $this->assertCount(0, $this->board()->home($this->household));

        $this->haSays(['person.jenna' => 'home', 'person.simon' => 'not_home', 'device_tracker.ellie_phone' => 'not_home']);

        $this->assertSame(['Jenna'], $this->board()->home($this->household)->pluck('name')->all());
    }

    #[Test]
    public function a_member_nobody_mapped_is_simply_in(): void
    {
        // Otherwise a household that sets up one phone watches everybody
        // else disappear into the away row.
        $this->household->setPresenceEntities([$this->mum->id => 'person.jenna']);

        $this->haSays(['person.jenna' => 'not_home']);

        $this->assertSame(['Simon', 'Ellie'], $this->board()->home($this->household)->pluck('name')->all());
    }

    #[Test]
    public function home_assistant_being_unreachable_does_not_send_everybody_out(): void
    {
        // "We do not know" and "nobody is home" are opposite answers, and
        // only one of them should move anybody.
        Cache::forget('ha:states');
        Http::fake(['*/api/states' => Http::response('nope', 500)]);

        $this->assertCount(3, $this->board()->home($this->household));
    }

    #[Test]
    public function an_entity_that_has_gone_unavailable_is_not_treated_as_away(): void
    {
        $this->haSays(['person.jenna' => 'unavailable', 'person.simon' => 'home', 'device_tracker.ellie_phone' => 'home']);

        $this->assertCount(3, $this->board()->home($this->household));
    }

    #[Test]
    public function with_nothing_mapped_at_all_the_feature_is_simply_off(): void
    {
        $this->household->setPresenceEntities([]);

        $this->assertFalse($this->board()->isConfigured($this->household->fresh()));
        $this->assertCount(3, $this->board()->home($this->household->fresh()));
    }

    #[Test]
    public function the_people_who_are_in_come_first(): void
    {
        $this->haSays(['person.jenna' => 'work', 'person.simon' => 'home', 'device_tracker.ellie_phone' => 'home']);

        $this->assertSame(
            ['Simon', 'Ellie', 'Jenna'],
            $this->board()->ordered($this->household)->pluck('name')->all(),
        );
    }

    #[Test]
    public function one_child_in_on_their_own_is_the_case_worth_noticing(): void
    {
        $this->haSays(['person.jenna' => 'work', 'person.simon' => 'work', 'device_tracker.ellie_phone' => 'home']);

        $this->assertSame('Ellie', $this->board()->loneChild($this->household)?->name);
    }

    #[Test]
    public function a_child_in_with_a_grown_up_is_not(): void
    {
        $this->haSays(['person.jenna' => 'home', 'person.simon' => 'work', 'device_tracker.ellie_phone' => 'home']);

        $this->assertNull($this->board()->loneChild($this->household));
    }

    #[Test]
    public function an_adult_in_on_their_own_is_not_either(): void
    {
        $this->haSays(['person.jenna' => 'home', 'person.simon' => 'work', 'device_tracker.ellie_phone' => 'school']);

        $this->assertNull($this->board()->loneChild($this->household));
    }

    #[Test]
    public function admin_can_see_the_computed_set_and_where_it_came_from(): void
    {
        $this->haSays(['person.jenna' => 'home', 'person.simon' => 'work', 'device_tracker.ellie_phone' => 'school']);

        $explained = $this->board()->explain($this->household);

        $this->assertTrue($explained['configured']);
        $this->assertTrue($explained['readable']);
        $this->assertSame('person.simon', $explained['rows'][1]['entity']);
        $this->assertSame('work', $explained['rows'][1]['raw']);
        $this->assertFalse($explained['rows'][1]['home']);
    }
}
