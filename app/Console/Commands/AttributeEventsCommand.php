<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Services\Attribution\EventAttributor;
use Illuminate\Console\Command;

class AttributeEventsCommand extends Command
{
    protected $signature = 'familyhub:attribute {--dry-run : Report what would change without writing}';

    protected $description = 'Re-read every event title against the current names, aliases and places';

    public function handle(EventAttributor $attributor): int
    {
        $household = Household::current();

        if ($this->option('dry-run')) {
            $this->components->info('Dry run — nothing will be written.');
            $this->preview($household, $attributor);

            return self::SUCCESS;
        }

        $changed = $attributor->applyToHousehold($household);

        $this->components->info($changed === 0
            ? 'Attribution re-run. Nothing changed.'
            : "Attribution re-run. {$changed} events updated.");

        // Worth saying out loud: a deploy that changes the rules will not touch
        // anything a person assigned by hand.
        $manual = $household->calendarAccounts()
            ->join('calendars', 'calendars.calendar_account_id', '=', 'calendar_accounts.id')
            ->join('events', 'events.calendar_id', '=', 'calendars.id')
            ->where('events.attribution', 'manual')
            ->count();

        if ($manual > 0) {
            $this->components->twoColumnDetail('Left alone (assigned by hand)', (string) $manual);
        }

        return self::SUCCESS;
    }

    protected function preview(Household $household, EventAttributor $attributor): void
    {
        $matcher = $attributor->matcherFor($household);
        $rows = 0;

        foreach ($household->calendarAccounts()->with('calendars')->get() as $account) {
            foreach ($account->calendars as $calendar) {
                foreach ($calendar->events()->where('attribution', 'auto')->with('members')->cursor() as $event) {
                    $matched = $matcher->match($event->title, $event->location);

                    if ($matched === [] && $calendar->member_id !== null) {
                        $matched = [$calendar->member_id => 'calendar'];
                    }

                    $before = $event->members->pluck('id')->sort()->values()->all();
                    $after = collect(array_keys($matched))->sort()->values()->all();

                    if ($before === $after) {
                        continue;
                    }

                    $names = fn (array $ids) => $household->members
                        ->whereIn('id', $ids)->pluck('name')->join(', ') ?: 'Household';

                    // twoColumnDetail dot-fills the gap, which swallows a bare
                    // arrow, so the whole transition goes in the right column.
                    $this->line(sprintf(
                        '  <options=bold>%s</>',
                        $event->title,
                    ));
                    $this->line(sprintf(
                        '    <fg=gray>%s</>  <fg=yellow>-></>  <fg=green>%s</>',
                        $names($before),
                        $names($after),
                    ));

                    $rows++;
                }
            }
        }

        $this->components->info($rows === 0 ? 'Nothing would change.' : "{$rows} events would change.");
    }
}
