<?php

namespace App\Services\Timers;

use App\Models\Household;
use App\Models\Timer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Every timer the household has going, and the few things done to them.
 *
 * Thin on purpose: the arithmetic lives on the row, because the browser does
 * the same arithmetic from the same end time and the two must not be able to
 * disagree.
 */
class TimerBoard
{
    /** How long a dismissed timer is kept before it is swept up. */
    public const KEEP_HOURS = 12;

    public function __construct(protected TimerParser $parser) {}

    /** @return Collection<int, Timer> */
    public function live(Household $household): Collection
    {
        return Timer::query()
            ->forHousehold($household)
            ->live()
            // Finished first — those are the ones somebody has to deal with —
            // then whichever goes off next.
            ->orderByRaw('CASE WHEN ends_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('ends_at')
            ->get()
            ->sortBy(fn (Timer $timer) => [$timer->hasFinished() ? 0 : 1, $timer->remaining()])
            ->values();
    }

    /**
     * What the wall needs to draw the strip.
     *
     * @return list<array<string, mixed>>
     */
    public function forScreen(Household $household): array
    {
        $now = CarbonImmutable::now();

        return $this->live($household)
            ->map(fn (Timer $timer) => $timer->forScreen($now))
            ->values()
            ->toBase()
            ->all();
    }

    public function start(Household $household, int $seconds, ?string $name = null, string $source = 'manual'): Timer
    {
        $seconds = max(1, min($seconds, Timer::MAX_SECONDS));

        return Timer::create([
            'household_id' => $household->id,
            'name' => filled($name) ? mb_substr(trim($name), 0, 60) : null,
            'seconds' => $seconds,
            'ends_at' => CarbonImmutable::now()->addSeconds($seconds),
            'source' => $source,
        ]);
    }

    /** A timer from something somebody said. */
    public function fromSpeech(Household $household, string $said): array
    {
        $request = $this->parser->parse($said);

        return [
            $this->start($household, $request->seconds, $request->name, 'voice'),
            $request,
        ];
    }

    /**
     * Mark the ones that have gone off as having gone off.
     *
     * The chime belongs to whichever screen notices first; recording it here
     * is what stops a wall and a phone both ringing for the same pasta.
     *
     * @return Collection<int, Timer> the ones that rang just now
     */
    public function ring(Household $household): Collection
    {
        $now = CarbonImmutable::now();

        return $this->live($household)
            ->filter(fn (Timer $timer) => $timer->hasFinished($now) && $timer->rang_at === null)
            ->each(fn (Timer $timer) => $timer->forceFill(['rang_at' => $now])->save())
            ->values();
    }

    /** Dismissed timers do not need keeping for ever. */
    public function sweep(Household $household): int
    {
        return Timer::query()
            ->forHousehold($household)
            ->whereNotNull('dismissed_at')
            ->where('dismissed_at', '<', CarbonImmutable::now()->subHours(self::KEEP_HOURS))
            ->delete();
    }
}
