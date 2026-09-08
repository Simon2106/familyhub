<?php

namespace Tests\Feature\Bins;

use App\Exceptions\IcalException;
use App\Models\BinCollection;
use App\Models\Household;
use App\Models\User;
use App\Services\Bins\BinSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Reading the council's calendar, and putting it on the wall. */
class BinCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    /** What the council is serving right now. Http::fake() MERGES stubs
     *  rather than replacing them, so the fake reads this at request time. */
    protected string $published = '';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-09 07:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        Http::fake(['*' => fn () => Http::response($this->published, 200, ['Content-Type' => 'text/calendar'])]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @param list<array{0: string, 1: string}> $events */
    protected function feed(array $events): string
    {
        $body = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Council//EN\r\n";

        foreach ($events as $i => [$date, $summary]) {
            $next = CarbonImmutable::parse($date)->addDay()->format('Ymd');
            $body .= "BEGIN:VEVENT\r\nUID:bin-{$i}@council\r\n"
                .'DTSTART;VALUE=DATE:'.CarbonImmutable::parse($date)->format('Ymd')."\r\n"
                ."DTEND;VALUE=DATE:{$next}\r\n"
                ."SUMMARY:{$summary}\r\nEND:VEVENT\r\n";
        }

        return $body."END:VCALENDAR\r\n";
    }

    protected function publish(array $events): void
    {
        $this->published = $this->feed($events);
        $this->household->setBinCalendarUrl('https://council.test/bins.ics');
    }

    protected function sync(): int
    {
        return app(BinSchedule::class)->sync($this->household->fresh());
    }

    #[Test]
    public function collections_are_read_from_the_feed(): void
    {
        $this->publish([
            ['2026-09-10', 'Refuse collection'],
            ['2026-09-17', 'Mixed dry recycling'],
        ]);

        $this->assertSame(2, $this->sync());
        $this->assertDatabaseHas('bin_collections', ['on' => '2026-09-10', 'kind' => 'refuse']);
        $this->assertDatabaseHas('bin_collections', ['on' => '2026-09-17', 'kind' => 'recycling']);
    }

    #[Test]
    public function councils_name_bins_however_they_like(): void
    {
        // Getting this wrong shows the wrong colour, which beats showing nothing.
        $expected = [
            'Refuse collection' => 'refuse',
            'Domestic waste' => 'refuse',
            'Black bin' => 'refuse',
            'Mixed dry recycling' => 'recycling',
            'Blue lidded bin' => 'recycling',
            // Its own round here, not part of the mixed recycling.
            'Paper and card' => 'paper',
            'Garden waste' => 'garden',
            'Food caddy' => 'food',
            'Small electricals' => 'electricals',
            'Something else entirely' => 'other',
        ];

        foreach ($expected as $summary => $kind) {
            $this->assertSame($kind, BinCollection::kindFor($summary), $summary);
        }
    }

    #[Test]
    public function a_resync_replaces_rather_than_piles_up(): void
    {
        $this->publish([['2026-09-10', 'Refuse collection']]);
        $this->sync();

        // The council moves the round.
        $this->publish([['2026-09-11', 'Refuse collection']]);
        $this->sync();

        $this->assertSame(1, BinCollection::count());
        $this->assertDatabaseHas('bin_collections', ['on' => '2026-09-11']);
        $this->assertDatabaseMissing('bin_collections', ['on' => '2026-09-10']);
    }

    #[Test]
    public function two_bins_on_one_day_are_both_kept(): void
    {
        $this->publish([
            ['2026-09-10', 'Refuse collection'],
            ['2026-09-10', 'Food caddy'],
        ]);

        $this->assertSame(2, $this->sync());
    }

    #[Test]
    public function the_same_bin_listed_twice_is_one_collection(): void
    {
        $this->publish([
            ['2026-09-10', 'Refuse collection'],
            ['2026-09-10', 'Domestic waste'],
        ]);

        $this->assertSame(1, $this->sync(), 'A careless feed is not two collections.');
    }

    #[Test]
    public function a_page_that_is_not_a_calendar_says_so_plainly(): void
    {
        $this->published = '<html><body>Please log in</body></html>';
        $this->household->setBinCalendarUrl('https://council.test/bins');

        $this->expectException(IcalException::class);
        $this->expectExceptionMessage('did not return a calendar');

        $this->sync();
    }

    #[Test]
    public function a_webcal_link_is_fetched_over_https(): void
    {
        $this->published = $this->feed([['2026-09-10', 'Refuse']]);
        $this->household->setBinCalendarUrl('webcal://council.test/bins.ics');

        $this->sync();

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://council.test/'));
    }

    #[Test]
    public function a_council_website_being_down_is_not_a_failed_night(): void
    {
        // Yesterday's answer is still on the wall and still right.
        $this->publish([['2026-09-10', 'Refuse collection']]);
        $this->sync();

        // Now the council site falls over.
        Http::fake(['*' => fn () => Http::response('nope', 500)]);

        $this->artisan('familyhub:sync-bins')->assertExitCode(0);
        $this->assertSame(1, BinCollection::count(), 'The known dates must survive a bad night.');
    }

    #[Test]
    public function no_calendar_configured_is_not_an_error(): void
    {
        $this->artisan('familyhub:sync-bins')
            ->expectsOutputToContain('No bin calendar set')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_wall_shows_a_collection_that_is_close(): void
    {
        BinCollection::factory()->create([
            'household_id' => $this->household->id,
            'on' => '2026-09-10',
            'name' => 'Mixed dry recycling',
            'kind' => 'recycling',
        ]);

        Livewire::test('display.wall')
            ->assertSee('Bins')
            ->assertSee('Recycling')
            ->assertSee('tonight');
    }

    #[Test]
    public function the_wall_says_today_when_it_is_today(): void
    {
        BinCollection::factory()->create([
            'household_id' => $this->household->id, 'on' => '2026-09-09', 'kind' => 'refuse',
        ]);

        Livewire::test('display.wall')->assertSee('today');
    }

    #[Test]
    public function a_collection_a_fortnight_away_is_not_worth_the_space(): void
    {
        BinCollection::factory()->create([
            'household_id' => $this->household->id, 'on' => '2026-09-23', 'kind' => 'garden',
        ]);

        // A wall that permanently says "recycling, a week on Tuesday" is a wall
        // nobody reads.
        $this->assertCount(0, Livewire::test('display.wall')->instance()->nextBins);
    }

    #[Test]
    public function both_bins_on_the_day_are_shown_together(): void
    {
        foreach (['refuse', 'food'] as $kind) {
            BinCollection::factory()->create([
                'household_id' => $this->household->id, 'on' => '2026-09-10', 'kind' => $kind,
            ]);
        }

        $this->assertCount(2, Livewire::test('display.wall')->instance()->nextBins);
    }

    #[Test]
    public function admin_can_check_the_address_there_and_then(): void
    {
        $this->published = $this->feed([['2026-09-10', 'Refuse collection']]);

        Livewire::test('admin.settings')
            ->set('binCalendarUrl', 'https://council.test/bins.ics')
            ->call('checkBins')
            ->assertDispatched('saved');

        $this->assertSame(1, BinCollection::count());
    }

    #[Test]
    public function a_wrong_address_is_found_now_rather_than_at_four_in_the_morning(): void
    {
        $this->published = '<html>Not a calendar</html>';

        Livewire::test('admin.settings')
            ->set('binCalendarUrl', 'https://council.test/wrong')
            ->call('checkBins')
            ->assertSee('did not return a calendar');
    }
}
