<?php

namespace App\Models;

use App\Exceptions\HouseholdNotProvisioned;
use Carbon\CarbonImmutable;
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
