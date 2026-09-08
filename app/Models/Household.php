<?php

namespace App\Models;

use App\Exceptions\HouseholdNotProvisioned;
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

    /** @param array<string, mixed> $values */
    protected function putSettings(array $values): void
    {
        $this->update(['settings' => array_merge($this->settings ?? [], $values)]);
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
