<?php

namespace App\Services\Notifications;

use App\Models\User;

/**
 * Which things a person wants to be told about, and how far ahead.
 *
 * Off by default, every one of them. A household that installs this and is
 * immediately buzzed about five different things is a household that turns
 * notifications off and never turns them back on.
 */
class NotificationSettings
{
    /** The triggers, in the order they are offered. */
    public const TRIGGERS = [
        'event_reminder' => 'Before an event',
        'review_waiting' => 'Things waiting in the review inbox',
        'approval_waiting' => 'Chores and rewards waiting for a grown-up',
        'chore_due' => 'A child\'s chore still not done',
        'list_added' => 'Something added to the shopping list',
        'weekly_summary' => 'The week, on a Sunday evening',
    ];

    /** How much warning, for the one trigger that needs a choice. */
    public const LEAD_TIMES = [
        15 => '15 minutes before',
        60 => 'An hour before',
        1440 => 'The day before',
    ];

    public const DEFAULT_LEAD = 60;

    public function wants(User $user, string $trigger): bool
    {
        return (bool) (($user->notify_settings['triggers'] ?? [])[$trigger] ?? false);
    }

    public function leadMinutes(User $user): int
    {
        $lead = (int) ($user->notify_settings['lead'] ?? self::DEFAULT_LEAD);

        return array_key_exists($lead, self::LEAD_TIMES) ? $lead : self::DEFAULT_LEAD;
    }

    public function anything(User $user): bool
    {
        return collect(self::TRIGGERS)->keys()->contains(fn (string $t) => $this->wants($user, $t));
    }

    /** @param array<string, bool> $triggers */
    public function put(User $user, array $triggers, int $lead): void
    {
        $user->forceFill(['notify_settings' => [
            'triggers' => collect(self::TRIGGERS)->keys()
                ->mapWithKeys(fn (string $t) => [$t => (bool) ($triggers[$t] ?? false)])
                ->all(),
            'lead' => array_key_exists($lead, self::LEAD_TIMES) ? $lead : self::DEFAULT_LEAD,
        ]])->save();
    }
}
