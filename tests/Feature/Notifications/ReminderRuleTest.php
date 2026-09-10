<?php

namespace Tests\Feature\Notifications;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\Place;
use App\Models\ReminderRule;
use App\Models\User;
use App\Services\Notifications\DueReminder;
use App\Services\Notifications\NoticeTriggers;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\ReminderEngine;
use App\Services\Notifications\ReminderTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reminders somebody built, rather than one lead time for everything.
 *
 * The tests that matter are about matching — who and what — and about the
 * preview agreeing with reality, since the preview is the only reason anybody
 * would trust a rule they wrote.
 */
class ReminderRuleTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected User $user;

    protected Calendar $calendar;

    protected Member $sienna;

    protected Member $joey;

    /** A Thursday morning. */
    protected const NOW = '2026-09-10 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(self::NOW);

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->user = User::factory()->create(['household_id' => $this->household->id]);

        $this->actingAs($this->user);

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id, 'name' => 'Family', 'is_visible' => true,
        ]);

        $this->sienna = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Sienna', 'is_child' => true,
        ]);
        $this->joey = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function event(string $title, string $startsAt, array $attributes = []): Event
    {
        return Event::factory()->create($attributes + [
            'calendar_id' => $this->calendar->id,
            'title' => $title,
            'start_at' => $startsAt,
            'end_at' => CarbonImmutable::parse($startsAt)->addHour(),
        ]);
    }

    protected function rule(array $attributes = []): ReminderRule
    {
        return ReminderRule::create($attributes + [
            'user_id' => $this->user->id,
            'household_id' => $this->household->id,
            'name' => 'A rule',
            'scope' => 'all',
            'members' => [],
            'times' => ['before:60'],
        ]);
    }

    protected function engine(): ReminderEngine
    {
        return app(ReminderEngine::class);
    }

    /* ------------------------------- times ------------------------------ */

    #[Test]
    public function the_three_shapes_of_when_are_read_back_correctly(): void
    {
        $this->assertSame(15, ReminderTime::parse('before:15')->minutes);
        $this->assertSame('07:00', ReminderTime::parse('morning:07:00')->at);
        $this->assertSame('19:00', ReminderTime::parse('prev:19:00')->at);

        $this->assertNull(ReminderTime::parse('before:soon'));
        $this->assertNull(ReminderTime::parse('morning:25:00'));
        $this->assertNull(ReminderTime::parse('nonsense'));
    }

    /**
     * The reason the clock kinds are not offsets: "the night before" means
     * seven o'clock whether the match is at nine in the morning or two in the
     * afternoon.
     */
    #[Test]
    public function the_evening_before_is_a_clock_time_not_an_offset(): void
    {
        $morning = ReminderTime::parse('prev:19:00')
            ->momentFor(CarbonImmutable::parse('2026-09-12 09:00:00'), 'Europe/London');

        $afternoon = ReminderTime::parse('prev:19:00')
            ->momentFor(CarbonImmutable::parse('2026-09-12 14:00:00'), 'Europe/London');

        $this->assertSame('2026-09-11 18:00', $morning->utc()->format('Y-m-d H:i'));
        $this->assertSame($morning->toDateTimeString(), $afternoon->toDateTimeString());
    }

    #[Test]
    public function that_morning_is_worked_out_in_the_households_own_timezone(): void
    {
        // 19:00 UTC on the 12th is 20:00 in London, still the 12th — so "that
        // morning" is the 12th at 07:00 local, which is 06:00 UTC.
        $moment = ReminderTime::parse('morning:07:00')
            ->momentFor(CarbonImmutable::parse('2026-09-12 19:00:00'), 'Europe/London');

        $this->assertSame('2026-09-12 06:00', $moment->utc()->format('Y-m-d H:i'));
    }

    #[Test]
    public function duplicate_and_unreadable_times_are_dropped(): void
    {
        $times = ReminderTime::list(['before:60', 'before:60', 'rubbish', 'morning:07:00']);

        $this->assertSame(
            ['morning:07:00', 'before:60'],
            array_map(fn (ReminderTime $t) => $t->toString(), $times),
            'Longest warning first, and only once each.',
        );
    }

    /* ------------------------------ matching ---------------------------- */

    #[Test]
    public function a_rule_for_everyone_matches_everything(): void
    {
        $this->event('Dentist', '2026-09-10 11:00:00');
        $rule = $this->rule();

        $due = $this->engine()->preview($rule, $this->household);

        $this->assertCount(1, $due);
        $this->assertSame('Dentist', $due[0]->event->title);
        $this->assertSame('2026-09-10 10:00', $due[0]->at->utc()->format('Y-m-d H:i'));
    }

    #[Test]
    public function a_rule_naming_members_ignores_events_that_are_not_theirs(): void
    {
        $hers = $this->event('Swimming', '2026-09-11 17:00:00');
        $hers->members()->attach($this->sienna->id);

        $his = $this->event('Football', '2026-09-11 18:00:00');
        $his->members()->attach($this->joey->id);

        $rule = $this->rule(['members' => [$this->sienna->id]]);

        $due = $this->engine()->preview($rule, $this->household);

        $this->assertSame(['Swimming'], array_map(fn (DueReminder $d) => $d->event->title, $due));
    }

    #[Test]
    public function several_members_are_an_any_of_not_an_all_of(): void
    {
        $hers = $this->event('Swimming', '2026-09-11 17:00:00');
        $hers->members()->attach($this->sienna->id);

        $his = $this->event('Football', '2026-09-11 18:00:00');
        $his->members()->attach($this->joey->id);

        $rule = $this->rule(['members' => [$this->sienna->id, $this->joey->id]]);

        $this->assertCount(2, $this->engine()->preview($rule, $this->household, 5));
    }

    #[Test]
    public function a_keyword_matches_the_title_whatever_the_case(): void
    {
        $this->event('SW Dentist appointment', '2026-09-11 09:00:00');
        $this->event('Football training', '2026-09-11 17:00:00');

        $rule = $this->rule(['scope' => 'keyword', 'keyword' => 'dentist']);

        $due = $this->engine()->preview($rule, $this->household, 5);

        $this->assertSame(['SW Dentist appointment'], array_map(fn (DueReminder $d) => $d->event->title, $due));
    }

    #[Test]
    public function a_calendar_rule_only_matches_that_calendar(): void
    {
        $other = Calendar::factory()->create([
            'calendar_account_id' => $this->calendar->calendar_account_id,
            'name' => 'Work',
            'is_visible' => true,
        ]);

        $this->event('Family thing', '2026-09-11 10:00:00');
        Event::factory()->create([
            'calendar_id' => $other->id,
            'title' => 'Work thing',
            'start_at' => '2026-09-11 11:00:00',
            'end_at' => '2026-09-11 12:00:00',
        ]);

        $rule = $this->rule(['scope' => 'calendar', 'calendar_id' => $other->id]);

        $due = $this->engine()->preview($rule, $this->household, 5);

        $this->assertSame(['Work thing'], array_map(fn (DueReminder $d) => $d->event->title, $due));
    }

    #[Test]
    public function a_place_matches_by_the_names_it_answers_to(): void
    {
        $school = Place::factory()->school()->create([
            'household_id' => $this->household->id, 'name' => 'Holy Trinity',
        ]);
        $school->aliases()->create(['alias' => 'HT', 'kind' => 'name']);

        $this->event('Parents evening', '2026-09-11 18:00:00', ['location' => 'Holy Trinity']);
        $this->event('HT sports day', '2026-09-12 10:00:00');
        $this->event('Cinema', '2026-09-12 14:00:00');

        $rule = $this->rule(['scope' => 'place', 'place_id' => $school->id]);

        $due = $this->engine()->preview($rule, $this->household, 5);

        $this->assertEqualsCanonicalizing(
            ['Parents evening', 'HT sports day'],
            array_map(fn (DueReminder $d) => $d->event->title, $due),
        );
    }

    #[Test]
    public function a_rule_about_one_event_matches_only_that_one(): void
    {
        $one = $this->event('Dentist', '2026-09-11 09:00:00');
        $this->event('Dentist', '2026-09-18 09:00:00');

        $rule = $this->rule(['scope' => 'event', 'event_id' => $one->id]);

        $due = $this->engine()->preview($rule, $this->household, 5);

        $this->assertCount(1, $due);
        $this->assertSame($one->id, $due[0]->event->id);
    }

    /* ---------------------------- repeats ------------------------------ */

    /**
     * Recurring events are stored as one row carrying an RRULE — the sync does
     * not expand them — so without expanding here the family would be told
     * about football once, in September, forever.
     */
    #[Test]
    public function a_repeating_event_produces_a_reminder_for_each_occurrence(): void
    {
        $this->event('Football training', '2026-09-11 17:00:00', [
            'rrule' => 'FREQ=WEEKLY;BYDAY=FR',
        ]);

        $rule = $this->rule(['times' => ['before:60']]);

        $due = $this->engine()->preview($rule, $this->household, 3);

        $this->assertCount(3, $due);
        $this->assertSame(
            ['2026-09-11 16:00', '2026-09-18 16:00', '2026-09-25 16:00'],
            array_map(fn (DueReminder $d) => $d->at->utc()->format('Y-m-d H:i'), $due),
        );
    }

    #[Test]
    public function each_occurrence_gets_its_own_ledger_subject(): void
    {
        $this->event('Football training', '2026-09-11 17:00:00', ['rrule' => 'FREQ=WEEKLY;BYDAY=FR']);

        $due = $this->engine()->preview($this->rule(), $this->household, 3);
        $subjects = array_map(fn (DueReminder $d) => $d->subject(), $due);

        // Keyed on the event alone, the first would be sent and then nothing
        // for a year.
        $this->assertCount(3, array_unique($subjects));
    }

    #[Test]
    public function a_one_event_rule_can_follow_the_whole_series(): void
    {
        $training = $this->event('Football training', '2026-09-11 17:00:00', [
            'rrule' => 'FREQ=WEEKLY;BYDAY=FR',
        ]);

        $following = $this->rule([
            'scope' => 'event', 'event_id' => $training->id, 'include_repeats' => true,
        ]);

        $once = $this->rule([
            'scope' => 'event', 'event_id' => $training->id, 'include_repeats' => false,
        ]);

        $this->assertCount(3, $this->engine()->preview($following, $this->household, 3));

        // Without repeats it is still one occurrence: the next one.
        $this->assertCount(1, $this->engine()->preview($once, $this->household, 3));
    }

    /* ---------------------------- several times ------------------------- */

    #[Test]
    public function one_rule_can_tell_them_more_than_once(): void
    {
        $this->event('Match', '2026-09-12 14:00:00');

        $rule = $this->rule(['times' => ['before:60', 'prev:19:00', 'morning:07:00']]);

        $due = $this->engine()->preview($rule, $this->household, 5);

        $this->assertSame(
            ['2026-09-11 18:00', '2026-09-12 06:00', '2026-09-12 13:00'],
            array_map(fn (DueReminder $d) => $d->at->utc()->format('Y-m-d H:i'), $due),
        );
    }

    /* ------------------------------ firing ------------------------------ */

    #[Test]
    public function a_rule_produces_a_notice_at_the_minute_it_is_due(): void
    {
        $this->event('Dentist', '2026-09-10 11:00:00');
        $this->rule(['times' => ['before:30']]);

        app(NotificationSettings::class)->put($this->user, ['event_reminder' => true]);
        $this->user->refresh();

        $notices = app(NoticeTriggers::class)->forUser(
            $this->user, $this->household, CarbonImmutable::parse('2026-09-10 10:30:00'),
        )->where('trigger', 'event_reminder');

        $this->assertCount(1, $notices);
        $this->assertSame('Dentist', $notices->first()->title);

        // And not a minute earlier.
        $early = app(NoticeTriggers::class)->forUser(
            $this->user, $this->household, CarbonImmutable::parse('2026-09-10 10:20:00'),
        )->where('trigger', 'event_reminder');

        $this->assertCount(0, $early);
    }

    #[Test]
    public function a_rule_that_is_switched_off_says_nothing(): void
    {
        $this->event('Dentist', '2026-09-10 11:00:00');
        $this->rule(['times' => ['before:30'], 'is_active' => false]);

        app(NotificationSettings::class)->put($this->user, ['event_reminder' => true]);
        $this->user->refresh();

        $notices = app(NoticeTriggers::class)->forUser(
            $this->user, $this->household, CarbonImmutable::parse('2026-09-10 10:30:00'),
        );

        $this->assertCount(0, $notices->where('trigger', 'event_reminder'));
    }

    #[Test]
    public function another_persons_rules_are_not_mine(): void
    {
        $this->event('Dentist', '2026-09-10 11:00:00');

        $jenna = User::factory()->create(['household_id' => $this->household->id]);

        $this->rule(['user_id' => $jenna->id, 'times' => ['before:30']]);

        $this->assertCount(0, $this->engine()->rules($this->user));
        $this->assertCount(1, $this->engine()->rules($jenna));
    }

    /** A rule made an hour too late must not announce something already begun. */
    #[Test]
    public function nothing_is_announced_about_an_event_that_has_started(): void
    {
        $this->event('Started already', '2026-09-10 08:00:00');

        $due = $this->engine()->preview($this->rule(['times' => ['before:15']]), $this->household, 5);

        $this->assertSame([], $due);
    }

    /* ------------------------------ the page ---------------------------- */

    #[Test]
    public function the_editor_previews_the_next_three_before_saving(): void
    {
        $this->event('Football training', '2026-09-11 17:00:00', ['rrule' => 'FREQ=WEEKLY;BYDAY=FR']);

        Livewire::test('notify.rules')
            ->call('add')
            ->set('name', 'Football')
            ->set('scope', 'keyword')
            ->set('keyword', 'football')
            ->call('toggleTime', 'prev:19:00')
            ->assertSee('The next three')
            ->assertSee('Football training')
            // Nothing has been written while it is only being looked at.
            ->assertCount('rules', 0);

        $this->assertSame(0, ReminderRule::count());
    }

    /**
     * The whole reason to show a preview: it has to be the same answer the
     * scheduler will give, or it is worse than showing nothing.
     */
    #[Test]
    public function the_preview_is_what_actually_happens(): void
    {
        $this->event('Match', '2026-09-12 14:00:00');

        $rule = $this->rule(['times' => ['prev:19:00']]);

        $preview = $this->engine()->preview($rule, $this->household, 1);

        $this->assertCount(1, $preview);

        $moment = $preview[0]->at;

        $due = $this->engine()->due($this->user, $this->household, $moment);

        $this->assertCount(1, $due);
        $this->assertSame($preview[0]->subject(), $due[0]->subject());
    }

    #[Test]
    public function a_rule_can_be_saved_switched_off_and_removed(): void
    {
        $this->event('Dentist', '2026-09-11 09:00:00');

        $component = Livewire::test('notify.rules')
            ->call('add')
            ->set('name', 'Dentist things')
            ->set('scope', 'keyword')
            ->set('keyword', 'dentist')
            ->call('save');

        $rule = ReminderRule::first();

        $this->assertNotNull($rule);
        $this->assertSame('Dentist things', $rule->name);
        $this->assertTrue($rule->is_active);

        $component->call('toggleActive', $rule->id);
        $this->assertFalse($rule->fresh()->is_active);

        $component->call('remove', $rule->id);
        $this->assertSame(0, ReminderRule::count());
    }

    #[Test]
    public function a_rule_with_no_times_is_refused(): void
    {
        Livewire::test('notify.rules')
            ->call('add')
            ->set('times', [])
            ->call('save')
            ->assertSee('at least one time');

        $this->assertSame(0, ReminderRule::count());
    }

    #[Test]
    public function an_unfinished_rule_is_refused(): void
    {
        Livewire::test('notify.rules')
            ->call('add')
            ->set('scope', 'calendar')
            ->set('calendarId', null)
            ->call('save')
            ->assertSee('not finished');

        $this->assertSame(0, ReminderRule::count());
    }

    /* ----------------------------- one-offs ----------------------------- */

    #[Test]
    public function remind_me_makes_a_rule_about_one_event(): void
    {
        $event = $this->event('School play', '2026-09-15 18:00:00');

        Livewire::test('notify.remind-me')
            ->call('open', $event->id)
            ->assertSee('School play')
            ->call('toggleTime', 'prev:19:00')
            ->call('save')
            ->assertSee('one-off');

        $rule = ReminderRule::first();

        $this->assertSame('event', $rule->scope);
        $this->assertSame($event->id, $rule->event_id);
        $this->assertTrue($rule->is_one_off);
        $this->assertSame($this->user->id, $rule->user_id);
    }

    #[Test]
    public function asking_twice_about_the_same_event_replaces_the_first(): void
    {
        $event = $this->event('School play', '2026-09-15 18:00:00');

        Livewire::test('notify.remind-me')->call('open', $event->id)->call('save');
        Livewire::test('notify.remind-me')
            ->call('open', $event->id)
            ->call('toggleTime', 'before:60')
            ->call('toggleTime', 'morning:07:00')
            ->call('save');

        $this->assertSame(1, ReminderRule::count());
        $this->assertSame(['morning:07:00'], ReminderRule::first()->times);
    }

    #[Test]
    public function a_one_off_can_be_taken_back(): void
    {
        $event = $this->event('School play', '2026-09-15 18:00:00');

        Livewire::test('notify.remind-me')->call('open', $event->id)->call('save');

        Livewire::test('notify.remind-me')->call('open', $event->id)->call('forget');

        $this->assertSame(0, ReminderRule::count());
    }

    #[Test]
    public function another_households_event_cannot_be_reminded_about(): void
    {
        $theirs = Event::factory()->create([
            'calendar_id' => Calendar::factory()->create([
                'calendar_account_id' => CalendarAccount::factory()->create([
                    'household_id' => Household::factory()->create()->id,
                ])->id,
            ])->id,
            'title' => 'Not ours',
            'start_at' => '2026-09-15 18:00:00',
            'end_at' => '2026-09-15 19:00:00',
        ]);

        Livewire::test('notify.remind-me')
            ->call('open', $theirs->id)
            ->call('save')
            ->assertDontSee('Not ours');

        $this->assertSame(0, ReminderRule::count());
    }
}
