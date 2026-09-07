<?php

namespace Database\Seeders;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\Household;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Fills the wall display with a plausible week so the layout can be judged
 * before any real calendar is connected. Everything it creates hangs off a
 * single "demo" calendar account and is removed on re-run.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $household = Household::current();

        // Re-running replaces the demo data rather than stacking more on top.
        CalendarAccount::where('household_id', $household->id)
            ->where('provider', 'demo')
            ->each(fn (CalendarAccount $a) => $a->delete());

        $account = CalendarAccount::create([
            'household_id' => $household->id,
            'provider' => 'demo',
            'label' => 'Demo data',
            'external_account_id' => 'demo@familyhub.local',
            'status' => 'ok',
            'last_synced_at' => now(),
        ]);

        $members = $household->members;

        if ($members->isEmpty()) {
            $this->command?->warn('No members to seed demo events for — run familyhub:seed-demo after setting FAMILYHUB_SEED_MEMBERS.');

            return;
        }

        // Split by who plausibly owns the event, so the demo does not put
        // "Piano practice" on a parent and "Book club" on a seven-year-old.
        $childEvents = [
            ['Swimming lesson', '16:30', 45, 'Leisure Centre'],
            ['Football training', '17:45', 90, 'Rec ground'],
            ['Piano practice', '17:00', 30, null],
            ['Beavers', '18:00', 75, 'Scout hut'],
            ['Playdate — Ellie', '15:30', 120, null],
            ['Reading with Miss Hall', '09:15', 20, 'School'],
        ];

        $adultEvents = [
            ['School run', '08:15', 30, "St Mary's"],
            ['Dentist', '11:00', 30, 'High Street Dental'],
            ['Book club', '19:30', 120, null],
            ['Parents evening', '18:00', 60, 'School hall'],
            ['Weekly shop', '10:00', 60, "Sainsbury's"],
            ['Team standup', '09:30', 30, null],
            ['Yoga', '19:00', 60, 'Village hall'],
        ];

        // Built in household time so "08:15" means 08:15 on the wall clock;
        // Eloquent converts to UTC on the way into the database.
        $tz = $household->displayTimezone();
        $today = $household->todayLocal();
        $created = 0;

        foreach ($members as $index => $member) {
            $calendar = Calendar::create([
                'calendar_account_id' => $account->id,
                'member_id' => $member->id,
                'external_id' => 'demo-'.$member->id,
                'name' => $member->name,
                'colour' => $member->colour,
            ]);

            // Spread events across the fortnight either side of today so the week
            // strip, today's agenda and the upcoming list all have content.
            for ($day = -2; $day <= 12; $day++) {
                if (($day + $index) % 2 !== 0) {
                    continue;
                }

                $pool = $member->is_child ? $childEvents : $adultEvents;
                [$title, $time, $minutes, $location] = $pool[abs($day + $index) % count($pool)];

                [$hour, $minute] = array_map('intval', explode(':', $time));
                $start = $today->addDays($day)->setTime($hour, $minute);

                Event::create([
                    'calendar_id' => $calendar->id,
                    'external_id' => sprintf('demo-%d-%d', $calendar->id, $day),
                    'title' => $title,
                    'start_at' => $start,
                    'end_at' => $start->addMinutes($minutes),
                    'all_day' => false,
                    'location' => $location,
                ]);

                $created++;
            }
        }

        // One all-day event so that path is exercised on the wall display too.
        // It belongs to a child if the household has one.
        $insetCalendar = $account->calendars()
            ->whereHas('member', fn ($q) => $q->where('is_child', true))
            ->first() ?? $account->calendars()->first();

        Event::create([
            'calendar_id' => $insetCalendar->id,
            'external_id' => 'demo-inset-day',
            'title' => 'Inset day — no school',
            'start_at' => $today->addDays(3)->startOfDay(),
            'end_at' => $today->addDays(3)->endOfDay(),
            'all_day' => true,
        ]);

        $this->seedChecklists($household);

        $this->command?->info(sprintf('Demo data: %d events across %d calendars.', $created + 1, $members->count()));
    }

    protected function seedChecklists(Household $household): void
    {
        $shopping = Checklist::firstOrCreate(
            ['household_id' => $household->id, 'name' => 'Shopping'],
            ['type' => 'shopping', 'sort_order' => 0],
        );

        if (! $shopping->items()->exists()) {
            foreach (['Milk', 'Bread', 'Bananas', 'Washing up liquid'] as $i => $title) {
                ChecklistItem::create(['checklist_id' => $shopping->id, 'title' => $title, 'sort_order' => $i]);
            }
        }

        // The home list is what the wall's To do panel shows.
        $todo = Checklist::home($household);
        $todo->update(['sort_order' => 1]);

        if ($todo->items()->exists()) {
            return;
        }

        $today = $household->todayLocal();
        $members = $household->members;

        $seed = [
            // [title, due offset in days or null, member index or null]
            ['Chase the plumber', -2, 0],
            ['Book MOT', 0, 0],
            ['Sign the school trip form', 1, 1],
            ['Return library books', 3, null],
            ['Water the plants', null, null],
            ['Buy Joey new football boots', null, 1],
        ];

        foreach ($seed as $i => [$title, $offset, $memberIndex]) {
            ChecklistItem::create([
                'checklist_id' => $todo->id,
                'title' => $title,
                'due_on' => $offset === null ? null : $today->addDays($offset)->toDateString(),
                'member_id' => $memberIndex === null ? null : ($members[$memberIndex]->id ?? null),
                'sort_order' => $i,
            ]);
        }
    }
}
