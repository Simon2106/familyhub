<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\User;
use App\Services\Notifications\NoticeTriggers;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\Notifier;
use Illuminate\Console\Command;

class RunNotifications extends Command
{
    protected $signature = 'familyhub:notify';

    protected $description = 'Tell the grown-ups about anything worth knowing';

    public function handle(
        NoticeTriggers $triggers,
        Notifier $notifier,
        NotificationSettings $settings,
    ): int {
        $household = Household::current();
        $now = $household->nowLocal();
        $told = 0;

        foreach (User::where('household_id', $household->id)->get() as $user) {
            if (! $settings->anything($user)) {
                continue;
            }

            foreach ($triggers->forUser($user, $household, $now) as $notice) {
                $told += $notifier->tell($user, $notice, $now) ? 1 : 0;
            }
        }

        if ($told > 0) {
            $this->components->info("{$told} to pass on.");
        }

        return self::SUCCESS;
    }
}
