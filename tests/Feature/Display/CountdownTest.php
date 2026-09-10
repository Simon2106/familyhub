<?php

namespace Tests\Feature\Display;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Countdown;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use App\Services\Countdowns\CountdownBoard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** How many sleeps until the things worth counting. */
class CountdownTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function board(): CountdownBoard
    {
        return app(CountdownBoard::class);
    }

    protected function countdown(string $label, string $on): Countdown
    {
        return Countdown::factory()->create([
            'household_id' => $this->household->id, 'label' => $label, 'on' => $on,
        ]);
    }

    protected function member(string $name, ?string $birthday = null): Member
    {
        return Member::factory()->create([
            'household_id' => $this->household->id, 'name' => $name, 'birthday' => $birthday,
        ]);
    }

    /* ------------------------------ counting ----------------------------- */

    #[Test]
    public function it_counts_the_days_in_words(): void
    {
        // "12 days until Cornwall" is what somebody says out loud.
        $this->countdown('Cornwall', '2026-09-22');

        $this->assertSame('12 days until Cornwall', $this->board()->upcoming($this->household)[0]->sentence());
    }

    #[Test]
    public function today_and_tomorrow_are_said_as_such(): void
    {
        $this->countdown('Cornwall', '2026-09-10');
        $this->countdown('Sports day', '2026-09-11');

        $entries = $this->board()->upcoming($this->household);

        $this->assertSame('Cornwall — today', $entries[0]->sentence());
        $this->assertSame('Tomorrow: Sports day', $entries[1]->sentence());
    }

    #[Test]
    public function something_that_has_been_and_gone_disappears(): void
    {
        $this->countdown('Last week', '2026-09-03');
        $this->countdown('Cornwall', '2026-09-22');

        $this->assertSame(['Cornwall'], $this->board()->upcoming($this->household)->map->label->all());
    }

    #[Test]
    public function the_soonest_come_first_and_only_a_few_are_shown(): void
    {
        foreach (['2026-12-25' => 'Christmas', '2026-09-22' => 'Cornwall', '2026-10-31' => 'Halloween', '2026-09-15' => 'Match'] as $on => $label) {
            $this->countdown($label, $on);
        }

        $entries = $this->board()->upcoming($this->household, 3);

        $this->assertSame(['Match', 'Cornwall', 'Halloween'], $entries->map->label->all());
    }

    /* ----------------------------- birthdays ----------------------------- */

    #[Test]
    public function a_birthday_comes_round_by_itself(): void
    {
        // Nobody has to remember to add next year's.
        $this->member('Joey', '2018-09-20');

        $entry = $this->board()->upcoming($this->household)[0];

        $this->assertSame('2026-09-20', $entry->on->toDateString());
        $this->assertSame("Joey's birthday — turning 8", $entry->label);
        $this->assertTrue($entry->isBirthday());
    }

    #[Test]
    public function one_that_has_passed_this_year_points_at_next_year(): void
    {
        $this->member('Sienna', '2016-03-04');

        $this->assertSame('2027-03-04', $this->board()->upcoming($this->household)[0]->on->toDateString());
    }

    #[Test]
    public function a_birthday_today_is_still_counted(): void
    {
        $this->member('Simon', '1985-09-10');

        $entries = $this->board()->upcoming($this->household);

        $this->assertCount(1, $entries);
        $this->assertTrue($entries[0]->isToday());
    }

    #[Test]
    public function the_twenty_ninth_of_february_still_has_a_birthday_every_year(): void
    {
        // A countdown that silently skipped three years in four would be a bug
        // nobody could see.
        $this->member('Leap', '2016-02-29');

        // 2027 is not a leap year, so it is kept as the 1st of March.
        $this->assertSame('2027-03-01', $this->board()->upcoming($this->household)[0]->on->toDateString());
    }

    #[Test]
    public function a_member_with_no_birthday_is_not_counted(): void
    {
        $this->member('Jenna');

        $this->assertCount(0, $this->board()->upcoming($this->household));
    }

    #[Test]
    public function an_age_is_only_claimed_when_the_year_is_known(): void
    {
        // Somebody who entered only the day gets a birthday without an age
        // rather than "turning 126".
        $this->member('Gran', '1900-07-04');

        $this->assertSame("Gran's birthday", $this->board()->upcoming($this->household)[0]->label);
    }

    #[Test]
    public function birthdays_and_made_countdowns_are_sorted_together(): void
    {
        $this->countdown('Cornwall', '2026-09-22');
        $this->member('Joey', '2018-09-20');

        $this->assertSame(
            ["Joey's birthday — turning 8", 'Cornwall'],
            $this->board()->upcoming($this->household)->map->label->all(),
        );
    }

    #[Test]
    public function a_year_out_is_a_date_rather_than_a_countdown(): void
    {
        $this->countdown('The wedding', '2028-06-01');

        $this->assertCount(0, $this->board()->upcoming($this->household));
    }

    /* -------------------------------- shown ------------------------------ */

    #[Test]
    public function the_wall_and_the_phone_both_show_them(): void
    {
        $this->countdown('Cornwall', '2026-09-22');
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        Livewire::test('display.wall')->assertSee('12 days until Cornwall');
        Livewire::test('phone.home')->assertSee('12 days until Cornwall');
    }

    #[Test]
    public function no_more_than_three_reach_the_wall(): void
    {
        foreach (['2026-09-11', '2026-09-12', '2026-09-13', '2026-09-14'] as $i => $on) {
            $this->countdown('Thing '.$i, $on);
        }

        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        Livewire::test('display.wall')
            ->assertSee('Thing 0')
            ->assertSee('Thing 2')
            ->assertDontSee('Thing 3');
    }

    #[Test]
    public function the_event_editor_knows_what_is_already_counted(): void
    {
        $member = $this->member('Simon');
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id, 'member_id' => $member->id,
        ]);
        $event = Event::factory()->create([
            'calendar_id' => $calendar->id,
            'title' => 'Cornwall',
            'start_at' => '2026-09-22 09:00:00',
            'end_at' => '2026-09-22 10:00:00',
        ]);

        Countdown::create([
            'household_id' => $this->household->id,
            'event_id' => $event->id,
            'label' => 'Cornwall',
            'on' => '2026-09-22',
        ]);

        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        Livewire::test('phone.event-editor')
            ->call('edit', $event->id)
            ->assertSet('countdown', true);
    }

    #[Test]
    public function a_countdown_keeps_the_words_it_was_given(): void
    {
        // An event renamed to "CANCELLED - Cornwall" should not silently
        // rewrite what the wall has been counting down to.
        $countdown = $this->countdown('Cornwall', '2026-09-22');

        $this->assertSame('Cornwall', $countdown->fresh()->label);
    }

    #[Test]
    public function the_day_count_survives_the_households_midnight_being_in_another_zone(): void
    {
        // Household midnight is 23:00 UTC the day before; a difference in
        // hours would be a day out for half of every year.
        CarbonImmutable::setTestNow('2026-06-15 23:30:00');

        $this->countdown('Cornwall', '2026-06-22');

        $this->assertSame(6, $this->board()->upcoming($this->household)[0]->days());
    }
}
