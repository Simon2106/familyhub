<?php

namespace App\Services\Notifications;

use App\Models\User;

/**
 * Which things a person wants to be told about.
 *
 * Off by default, every one of them. A household that installs this and is
 * immediately buzzed about five different things is a household that turns
 * notifications off and never turns them back on.
 */
class NotificationSettings
{
    /** The triggers, in the order they are offered. */
    public const TRIGGERS = [
        'event_reminder' => 'Reminders about events',
        'review_waiting' => 'Things waiting in the review inbox',
        'approval_waiting' => 'Chores and rewards waiting for a grown-up',
        'chore_due' => 'A child\'s chore still not done',
        'list_added' => 'Something added to the shopping list',
        'weekly_summary' => 'The week, on a Sunday evening',
    ];

    public function wants(User $user, string $trigger): bool
    {
        return (bool) (($user->notify_settings['triggers'] ?? [])[$trigger] ?? false);
    }

    public function anything(User $user): bool
    {
        return collect(self::TRIGGERS)->keys()->contains(fn (string $t) => $this->wants($user, $t));
    }

    /**
     * @param  array<string, bool>  $triggers
     *
     * There is no lead time here any more. "So many minutes before
     * everything" was the setting nobody wanted — it is either noise or
     * silence — and it has been replaced by rules the family builds
     * themselves. See ReminderRule.
     */
    public function put(User $user, array $triggers): void
    {
        $user->forceFill(['notify_settings' => [
            'triggers' => collect(self::TRIGGERS)->keys()
                ->mapWithKeys(fn (string $t) => [$t => (bool) ($triggers[$t] ?? false)])
                ->all(),
        ]])->save();
    }
}
