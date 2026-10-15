<?php

namespace Tests\Feature\HomeAssistant;

use App\Models\ActivityEvent;
use App\Models\Household;
use App\Models\User;
use App\Services\Activity\ActivityFeed;
use App\Services\Activity\ActivityRecorder;
use App\Services\HomeAssistant\Client;
use App\Services\HomeAssistant\HomeAssistant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the house has been doing, in sentences somebody would say.
 */
class ActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

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

        $this->household->setActivityEntities([
            'person.jenna' => ['label' => 'Jenna', 'category' => 'people'],
            'binary_sensor.back_door' => ['label' => 'Back door', 'category' => 'doors'],
            'climate.hall' => ['label' => 'Heating', 'category' => 'heating'],
            'light.kitchen' => ['label' => 'Kitchen light', 'category' => 'lights'],
        ]);
    }

    protected function feed(): ActivityFeed
    {
        return app(ActivityFeed::class);
    }

    protected function happened(string $entity, string $to, string $at, ?string $from = 'x'): ?ActivityEvent
    {
        return app(ActivityRecorder::class)->record(
            $this->household,
            $entity,
            $from,
            $to,
            CarbonImmutable::parse($at),
        );
    }

    /* ----------------------------- sentences ---------------------------- */

    #[Test]
    public function a_state_change_becomes_something_a_person_would_say(): void
    {
        // "binary_sensor.back_door went from off to on" is a log line.
        $this->happened('person.jenna', 'home', '2026-10-15 18:00:00');
        $this->happened('binary_sensor.back_door', 'on', '2026-10-15 18:00:30');
        $this->happened('climate.hall', '20', '2026-10-15 18:01:00');

        $latest = $this->feed()->latest($this->household, CarbonImmutable::parse('2026-10-15 18:05:00'));

        $this->assertSame('Jenna home · Back door opened · Heating to 20°', $latest['sentence']);
    }

    #[Test]
    public function a_person_leads_the_cluster_wherever_in_it_they_turned_up(): void
    {
        // A door, a light and a person in the same five minutes is one
        // person getting home, and that is how anybody would tell you.
        $this->happened('binary_sensor.back_door', 'on', '2026-10-15 18:00:00');
        $this->happened('light.kitchen', 'on', '2026-10-15 18:00:20');
        $this->happened('person.jenna', 'home', '2026-10-15 18:01:00');

        $latest = $this->feed()->latest($this->household, CarbonImmutable::parse('2026-10-15 18:05:00'));

        $this->assertStringStartsWith('Jenna home', $latest['sentence']);
        $this->assertSame('Jenna', $latest['person']);
    }

    #[Test]
    public function a_named_zone_reads_better_than_left(): void
    {
        $this->happened('person.jenna', 'school', '2026-10-15 08:40:00');

        $this->assertSame(
            'Jenna at school',
            $this->feed()->latest($this->household, CarbonImmutable::parse('2026-10-15 08:45:00'))['sentence'],
        );
    }

    #[Test]
    public function plain_not_home_is_simply_left(): void
    {
        $this->happened('person.jenna', 'not_home', '2026-10-15 08:40:00');

        $this->assertSame(
            'Jenna left',
            $this->feed()->latest($this->household, CarbonImmutable::parse('2026-10-15 08:45:00'))['sentence'],
        );
    }

    #[Test]
    public function the_same_door_twice_in_five_minutes_is_still_one_door(): void
    {
        $this->happened('binary_sensor.back_door', 'on', '2026-10-15 18:00:00');
        $this->happened('binary_sensor.back_door', 'off', '2026-10-15 18:00:10', from: 'on');
        $this->happened('binary_sensor.back_door', 'on', '2026-10-15 18:00:20', from: 'off');

        $latest = $this->feed()->latest($this->household, CarbonImmutable::parse('2026-10-15 18:05:00'));

        $this->assertSame('Back door opened · Back door closed', $latest['sentence']);
    }

    /* ----------------------------- clustering --------------------------- */

    #[Test]
    public function things_more_than_five_minutes_apart_are_different_things(): void
    {
        $this->happened('person.jenna', 'home', '2026-10-15 18:00:00');
        $this->happened('light.kitchen', 'on', '2026-10-15 18:30:00');

        $clusters = $this->feed()->clusters($this->household, null, CarbonImmutable::parse('2026-10-15 18:35:00'));

        $this->assertCount(2, $clusters);
        $this->assertSame('Kitchen light on', $clusters[0]['sentence'], 'Newest first.');
        $this->assertSame('Jenna home', $clusters[1]['sentence']);
    }

    #[Test]
    public function the_last_day_is_grouped_by_the_hour_it_happened_in(): void
    {
        $this->happened('person.jenna', 'home', '2026-10-15 18:00:00');
        $this->happened('light.kitchen', 'on', '2026-10-15 19:10:00');

        $hours = $this->feed()->byHour($this->household, CarbonImmutable::parse('2026-10-15 19:30:00'));

        // Grouped by the household's own clock, not the server's: these were
        // recorded at 18:00 and 19:10 UTC, which is seven and eight in the
        // evening in a kitchen in October.
        $this->assertSame(['20:00', '19:00'], $hours->keys()->all());
    }

    /* ---------------------------- quiet hours --------------------------- */

    #[Test]
    public function lights_during_quiet_hours_are_not_surfaced(): void
    {
        $this->household->setActivityQuietHours('22:00', '07:00');

        $this->happened('light.kitchen', 'on', '2026-10-15 23:30:00');

        $this->assertCount(
            0,
            $this->feed()->clusters($this->household, null, CarbonImmutable::parse('2026-10-15 23:45:00')),
        );
    }

    #[Test]
    public function somebody_coming_home_at_two_in_the_morning_still_is(): void
    {
        // The quiet hours drop a category, not a window: a person arriving
        // in the small hours is exactly the thing worth a line.
        $this->household->setActivityQuietHours('22:00', '07:00');

        $this->happened('person.jenna', 'home', '2026-10-16 02:00:00');

        $this->assertSame(
            'Jenna home',
            $this->feed()->latest($this->household, CarbonImmutable::parse('2026-10-16 02:05:00'))['sentence'],
        );
    }

    #[Test]
    public function a_light_outside_quiet_hours_is_fine(): void
    {
        $this->household->setActivityQuietHours('22:00', '07:00');

        $this->happened('light.kitchen', 'on', '2026-10-15 18:00:00');

        $this->assertCount(
            1,
            $this->feed()->clusters($this->household, null, CarbonImmutable::parse('2026-10-15 18:05:00')),
        );
    }

    /* ----------------------------- recording ---------------------------- */

    #[Test]
    public function only_the_entities_somebody_chose_are_recorded(): void
    {
        // A Zigbee network invents a couple of hundred entities.
        $this->assertNull($this->happened('sensor.some_battery', '94', '2026-10-15 18:00:00'));
        $this->assertSame(0, ActivityEvent::count());
    }

    #[Test]
    public function a_state_that_did_not_change_is_not_news(): void
    {
        $this->assertNull($this->happened('light.kitchen', 'on', '2026-10-15 18:00:00', from: 'on'));
    }

    #[Test]
    public function ha_losing_touch_with_something_is_not_news_either(): void
    {
        $this->assertNull($this->happened('light.kitchen', 'unavailable', '2026-10-15 18:00:00'));
        $this->assertNull($this->happened('light.kitchen', 'unknown', '2026-10-15 18:00:00'));
    }

    #[Test]
    public function the_listener_hands_over_a_state_changed_event(): void
    {
        $event = app(ActivityRecorder::class)->fromListener($this->household, [
            'entity_id' => 'binary_sensor.back_door',
            'old_state' => ['state' => 'off'],
            'new_state' => ['state' => 'on'],
        ]);

        $this->assertNotNull($event);
        $this->assertSame('doors', $event->category);
        $this->assertSame('Back door', $event->label);
    }

    #[Test]
    public function the_poll_notices_what_changed_since_it_last_looked(): void
    {
        Cache::flush();

        $states = ['light.kitchen' => 'off'];

        Http::fake(['*/api/states' => function () use (&$states) {
            return Http::response(collect($states)->map(fn ($s, $id) => [
                'entity_id' => $id, 'state' => $s, 'attributes' => [],
            ])->values()->all());
        }]);

        $recorder = fn () => new ActivityRecorder(
            new HomeAssistant(new Client)
        );

        // The first look is a baseline, not an event — otherwise turning
        // this on writes a row for every watched entity in the house.
        Cache::forget('ha:states');
        $this->assertSame(0, $recorder()->poll($this->household));
        $this->assertSame(0, ActivityEvent::count());

        $states['light.kitchen'] = 'on';
        Cache::forget('ha:states');

        $this->assertSame(1, $recorder()->poll($this->household));
        $this->assertSame('Kitchen light on', $this->feed()->latest($this->household)['sentence']);
    }

    #[Test]
    public function home_assistant_being_unreachable_writes_nothing_and_does_not_throw(): void
    {
        Cache::flush();
        Http::fake(['*/api/states' => Http::response('nope', 500)]);

        $this->assertSame(0, app(ActivityRecorder::class)->poll($this->household));
    }

    #[Test]
    public function with_nothing_chosen_the_feed_is_simply_off(): void
    {
        $this->household->setActivityEntities([]);

        $this->assertSame(0, app(ActivityRecorder::class)->poll($this->household->fresh()));
        $this->assertNull($this->feed()->latest($this->household->fresh()));
    }

    #[Test]
    public function what_the_house_did_a_month_ago_is_forgotten(): void
    {
        $this->happened('light.kitchen', 'on', '2026-09-01 18:00:00');
        $this->happened('light.kitchen', 'off', '2026-10-15 18:00:00', from: 'on');

        $this->assertSame(2, ActivityEvent::count());

        $this->feed()->prune(CarbonImmutable::parse('2026-10-15 23:00:00'));

        $this->assertSame(1, ActivityEvent::count());
    }
}
