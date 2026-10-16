<?php

namespace App\Services\Display;

use App\Models\Household;
use Carbon\CarbonImmutable;

/**
 * Which face the wall is wearing.
 *
 * A kitchen screen is asked different questions at different hours. At ten
 * past seven it is "has he got his PE kit"; at six it is "what's for tea";
 * at eleven it should be a photograph and a clock and nothing else. Showing
 * the same grid for all three is how a wall becomes wallpaper.
 *
 * The boundaries are times of day rather than a schedule, so the whole thing
 * is four strings somebody can change in /admin and the browser can evaluate
 * on its own every minute without asking the server anything.
 */
class WallFace
{
    public const DAY = 'day';

    public const EVENING = 'evening';

    public const NIGHT = 'night';

    /** In the order they happen, which is the order the resolver walks. */
    public const ORDER = [self::DAY, self::EVENING, self::NIGHT];

    public const LABELS = [
        self::DAY => 'Day',
        self::EVENING => 'Evening',
        self::NIGHT => 'Night',
    ];

    /** The greeting each face leads with, top left. */
    public const GREETINGS = [
        self::DAY => 'Today',
        self::EVENING => 'Tonight',
        self::NIGHT => 'Good night',
    ];

    public const DEFAULTS = [
        self::DAY => '06:30',
        self::EVENING => '17:00',
        self::NIGHT => '21:00',
    ];

    /**
     * The face for a moment.
     *
     * There was a morning face once — a sparser board of routines and what
     * had to go in a bag. It was removed: the household wanted the full day
     * grid from the moment they came downstairs, which is what the day face
     * already is. Day now starts at half six.
     */
    public function at(Household $household, ?CarbonImmutable $now = null): string
    {
        $now ??= $household->nowLocal();
        $times = $household->faceTimes();

        $face = self::NIGHT;

        // Walk forward through the day; the last boundary passed wins. Before
        // the first boundary it is still last night, which is why this starts
        // on night rather than on the first face.
        foreach (self::ORDER as $candidate) {
            if ($this->minutesOf($now->format('H:i')) >= $this->minutesOf($times[$candidate])) {
                $face = $candidate;
            }
        }

        return $face;
    }

    /**
     * The boundaries as the browser wants them: minutes past midnight.
     *
     * Sent down once and re-evaluated client-side every minute, so the face
     * turns over on the hour rather than whenever the next poll lands.
     *
     * @return array<string, int>
     */
    public function boundaries(Household $household): array
    {
        $times = $household->faceTimes();

        return array_map(fn (string $time) => $this->minutesOf($time), $times);
    }

    protected function minutesOf(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $hours * 60 + $minutes;
    }
}
