<?php

namespace App\Models;

use App\Exceptions\HouseholdNotProvisioned;
use App\Services\PhotoLibrary;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'timezone', 'settings'])]
class Household extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /** @return HasMany<Member, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class)->orderBy('sort_order')->orderBy('name');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<CalendarAccount, $this> */
    public function calendarAccounts(): HasMany
    {
        return $this->hasMany(CalendarAccount::class);
    }

    /** @return HasMany<Place, $this> */
    public function places(): HasMany
    {
        return $this->hasMany(Place::class)->orderBy('name');
    }

    /** @return HasMany<Checklist, $this> */
    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The zone the family actually lives in. Rows are stored in UTC; this is
     * the zone times are shown in and whose midnight defines "a day".
     */
    public function displayTimezone(): string
    {
        return $this->timezone ?: config('familyhub.timezone');
    }

    /** "Now" on the kitchen wall, not on the server. */
    public function nowLocal(): CarbonImmutable
    {
        return CarbonImmutable::now($this->displayTimezone());
    }

    /** Midnight tonight-just-gone, in household time. */
    public function todayLocal(): CarbonImmutable
    {
        return $this->nowLocal()->startOfDay();
    }

    /**
     * How many days a ticked to-do stays under "Done" before being deleted.
     *
     * Stored in the settings blob rather than its own column: it is a
     * preference, and more will follow it.
     */
    public function doneRetentionDays(): int
    {
        $days = (int) (($this->settings['done_retention_days'] ?? null)
            ?: config('familyhub.todos.done_retention_days'));

        // A zero or negative retention would delete items as soon as they were
        // ticked, which is indistinguishable from losing them.
        return max($days, 1);
    }

    public function setDoneRetentionDays(int $days): void
    {
        $this->putSettings(['done_retention_days' => max($days, 1)]);
    }

    /**
     * How many days before its due date a to-do starts being shown.
     *
     * A form due in six weeks should be capturable today without sitting on
     * the wall for six weeks. Zero is allowed and means "only on the day".
     */
    public function todoLeadDays(): int
    {
        $days = $this->settings['todo_lead_days'] ?? null;

        return max((int) ($days ?? config('familyhub.todos.lead_days')), 0);
    }

    public function setTodoLeadDays(int $days): void
    {
        $this->putSettings(['todo_lead_days' => max($days, 0)]);
    }

    /** Whether accepting a dated task also puts a reminder in iCloud. */
    public function mirrorsDeadlineTasks(): bool
    {
        return (bool) ($this->settings['mirror_deadline_tasks'] ?? false)
            && $this->mirrorCalendar() !== null;
    }

    /** The calendar reminders are mirrored into, if one is still writable. */
    public function mirrorCalendar(): ?Calendar
    {
        $id = $this->settings['mirror_calendar_id'] ?? null;

        if (! $id) {
            return null;
        }

        return Calendar::query()
            ->whereHas('account', fn ($q) => $q->where('household_id', $this->id))
            ->where('is_writable', true)
            ->find($id);
    }

    public function setDeadlineMirror(bool $on, ?int $calendarId = null): void
    {
        $this->putSettings([
            'mirror_deadline_tasks' => $on,
            'mirror_calendar_id' => $calendarId,
        ]);
    }

    /**
     * Which meals the household plans.
     *
     * Dinner only by default: most families plan one meal a day, and a grid
     * with three empty rows per day looks like work nobody has done rather
     * than a plan.
     *
     * @return list<string>
     */
    public function mealSlots(): array
    {
        $slots = $this->settings['meal_slots'] ?? null;

        if (! is_array($slots) || $slots === []) {
            return Meal::DEFAULT_SLOTS;
        }

        // Kept in meal order however they were stored, so the grid never shows
        // dinner above breakfast.
        return array_values(array_intersect(Meal::SLOTS, $slots));
    }

    /** @param list<string> $slots */
    public function setMealSlots(array $slots): void
    {
        $slots = array_values(array_intersect(Meal::SLOTS, $slots));

        // Dinner is the floor: a plan with no rows at all is not a plan.
        $this->putSettings(['meal_slots' => $slots ?: Meal::DEFAULT_SLOTS]);
    }

    /** Monday, because the wall calendar and the paper planner both start there. */
    public function weekStart(?CarbonImmutable $from = null): CarbonImmutable
    {
        return ($from ?? $this->todayLocal())->startOfWeek(CarbonInterface::MONDAY);
    }

    /**
     * Whether points also become pocket money.
     *
     * Off by default: a household that wants stars to stay stars should not
     * have to turn money off.
     */
    public function allowanceEnabled(): bool
    {
        return (bool) ($this->settings['allowance_enabled'] ?? false);
    }

    /** Pence per point, so a rate of 5 makes 20 points a pound. */
    public function allowancePencePerPoint(): float
    {
        return max((float) ($this->settings['allowance_pence_per_point'] ?? 0), 0);
    }

    public function setAllowance(bool $enabled, float $pencePerPoint): void
    {
        $this->putSettings([
            'allowance_enabled' => $enabled,
            'allowance_pence_per_point' => max($pencePerPoint, 0),
        ]);
    }

    /**
     * When the wall goes black overnight.
     *
     * The kiosk monitor cannot be power-cycled remotely, so "off" is a state
     * of the page rather than of the screen. Off by default: a household that
     * has not asked for it should not find its wall dark one evening.
     *
     * @return array{enabled: bool, start: string, end: string}
     */
    public function screenOff(): array
    {
        return [
            'enabled' => (bool) ($this->settings['screen_off_enabled'] ?? false),
            'start' => $this->timeSetting('screen_off_start', '23:00'),
            'end' => $this->timeSetting('screen_off_end', '06:30'),
        ];
    }

    public function setScreenOff(bool $enabled, string $start, string $end): void
    {
        $this->putSettings([
            'screen_off_enabled' => $enabled,
            'screen_off_start' => $this->cleanTime($start, '23:00'),
            'screen_off_end' => $this->cleanTime($end, '06:30'),
        ]);
    }

    protected function timeSetting(string $key, string $fallback): string
    {
        return $this->cleanTime((string) ($this->settings[$key] ?? ''), $fallback);
    }

    /** A malformed time must never black out the wall, so it falls back. */
    protected function cleanTime(string $value, string $fallback): string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($value)) ? trim($value) : $fallback;
    }

    /**
     * When the wall dims itself.
     *
     * Config supplies the first answer and the household overrides it: an
     * installation default is a sensible thing to ship, and a wall that can
     * only be re-timed by editing .env is not.
     *
     * @return array{start: string, end: string}
     */
    public function darkMode(): array
    {
        return [
            'start' => $this->timeSetting('dark_start', (string) config('familyhub.dark_mode.start')),
            'end' => $this->timeSetting('dark_end', (string) config('familyhub.dark_mode.end')),
        ];
    }

    public function setDarkMode(string $start, string $end): void
    {
        $this->putSettings([
            'dark_start' => $this->cleanTime($start, (string) config('familyhub.dark_mode.start')),
            'dark_end' => $this->cleanTime($end, (string) config('familyhub.dark_mode.end')),
        ]);
    }

    /** Minutes of nobody touching the wall before the screensaver. 0 = never. */
    public function screensaverMinutes(): int
    {
        $minutes = $this->settings['screensaver_minutes']
            ?? config('familyhub.screensaver.idle_minutes');

        return max(0, min(240, (int) $minutes));
    }

    public function setScreensaverMinutes(int $minutes): void
    {
        $this->putSettings(['screensaver_minutes' => max(0, min(240, $minutes))]);
    }

    /** clock | today | photos */
    public const SCREENSAVER_STYLES = [
        'clock' => 'A big drifting clock',
        'today' => 'Clock, today and the weather',
        'photos' => 'Photographs, with the clock and today over them',
    ];

    /**
     * What the screensaver shows.
     *
     * Unset means "whatever this household was already getting" — photographs
     * where there are any, the clock where there are not — so switching the
     * setting on does not silently take the family's photos away.
     */
    public function screensaverStyle(): string
    {
        $style = (string) ($this->settings['screensaver_style'] ?? '');

        if (array_key_exists($style, self::SCREENSAVER_STYLES)) {
            return $style;
        }

        return $this->hasPhotos() ? 'photos' : 'clock';
    }

    public function setScreensaverStyle(string $style): void
    {
        $this->putSettings([
            'screensaver_style' => array_key_exists($style, self::SCREENSAVER_STYLES) ? $style : 'clock',
        ]);
    }

    /** A public iCloud shared album the screensaver pulls from. */
    public function photoAlbumUrl(): ?string
    {
        return filled($this->settings['photo_album_url'] ?? null)
            ? (string) $this->settings['photo_album_url']
            : null;
    }

    public function setPhotoAlbumUrl(?string $url): void
    {
        $this->putSettings(['photo_album_url' => filled($url) ? trim($url) : null]);
    }

    public function hasPhotos(): bool
    {
        // Through the library the wall already reads, rather than a second
        // opinion about the same directory.
        return app(PhotoLibrary::class)->urls(1) !== [];
    }

    /**
     * Whether the wall reads its answers out loud.
     *
     * On by default, because a microphone you have to read the reply from is a
     * strange thing to have installed. Turned off in /admin for the evenings
     * when a talking kitchen is the last thing anybody wants.
     */
    public function wallSpeaks(): bool
    {
        return (bool) ($this->settings['wall_speaks'] ?? true);
    }

    public function setWallSpeaks(bool $speaks): void
    {
        $this->putSettings(['wall_speaks' => $speaks]);
    }

    /** Where bin dates come from: 'pattern', 'ical', or nowhere. */
    public function binSource(): string
    {
        $source = (string) ($this->settings['bin_source'] ?? '');

        if (in_array($source, ['pattern', 'ical'], true)) {
            return $source;
        }

        // Nothing chosen: infer it, so a household that only ever pasted a URL
        // keeps working without visiting settings again.
        return $this->binCalendarUrl() ? 'ical' : 'none';
    }

    public function setBinSource(string $source): void
    {
        $this->putSettings([
            'bin_source' => in_array($source, ['pattern', 'ical'], true) ? $source : 'none',
        ]);
    }

    /** @return array<string, mixed>|null */
    public function binPatternSettings(): ?array
    {
        $pattern = $this->settings['bin_pattern'] ?? null;

        return is_array($pattern) ? $pattern : null;
    }

    /** @param array<string, mixed> $pattern */
    public function setBinPattern(array $pattern): void
    {
        $this->putSettings(['bin_pattern' => $pattern]);
    }

    /**
     * A one-off move, for the bank holidays a fixed pattern cannot know about.
     *
     * @return array{on: string, moved_to: string}|null
     */
    public function binOverride(): ?array
    {
        $override = $this->settings['bin_override'] ?? null;

        if (! is_array($override) || blank($override['on'] ?? null) || blank($override['moved_to'] ?? null)) {
            return null;
        }

        // Spent once the day it moved to has passed. Left in place it would
        // silently shift next Christmas as well.
        if ($override['moved_to'] < $this->todayLocal()->toDateString()) {
            return null;
        }

        return ['on' => (string) $override['on'], 'moved_to' => (string) $override['moved_to']];
    }

    public function setBinOverride(?string $on, ?string $movedTo): void
    {
        $this->putSettings([
            'bin_override' => $on && $movedTo ? ['on' => $on, 'moved_to' => $movedTo] : null,
        ]);
    }

    /** The council's bin calendar for this address, if one has been set. */
    public function binCalendarUrl(): ?string
    {
        $url = trim((string) ($this->settings['bin_ical_url'] ?? ''));

        return $url !== '' ? $url : null;
    }

    public function setBinCalendarUrl(?string $url): void
    {
        $this->putSettings(['bin_ical_url' => trim((string) $url) ?: null]);
    }

    /** @return HasMany<BinCollection, $this> */
    public function binCollections(): HasMany
    {
        return $this->hasMany(BinCollection::class)->orderBy('on');
    }

    /**
     * Merge into the settings blob, reading it back first.
     *
     * Merging onto this instance's copy loses whatever anyone else wrote since
     * it was loaded — and `Household::current()` is memoised per request, so
     * "since it was loaded" can be a while. Two saves in one request, or a save
     * beside a background write, would silently drop one of them.
     *
     * @param  array<string, mixed>  $values
     */
    protected function putSettings(array $values): void
    {
        $current = $this->newQueryWithoutScopes()->find($this->getKey())?->settings ?? [];

        $settings = array_merge(is_array($current) ? $current : [], $values);

        $this->update(['settings' => $settings]);
    }

    /**
     * The single household this installation serves. FamilyHub is deliberately
     * single-tenant, so this is cached for the request rather than looked up.
     */
    public static function current(): self
    {
        return once(fn () => static::query()->orderBy('id')->first()
            ?? throw new HouseholdNotProvisioned);
    }
}
