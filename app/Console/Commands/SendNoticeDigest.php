<?php

namespace App\Console\Commands;

use App\Mail\NoticeDigest;
use App\Models\Household;
use App\Models\NoticeSent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The morning's catch-up.
 *
 * Anything recorded but never actually sent — because there was no push set
 * up, because the device had gone, or because it happened in the quiet hours —
 * is carried by email instead. Sent once and marked, so a digest is never the
 * same list twice.
 */
class SendNoticeDigest extends Command
{
    protected $signature = 'familyhub:notice-digest';

    protected $description = 'Email anything the household was not told at the time';

    public function handle(): int
    {
        $household = Household::current();
        $sent = 0;

        foreach (User::where('household_id', $household->id)->get() as $user) {
            $waiting = NoticeSent::where('user_id', $user->id)
                ->whereNull('sent_at')
                ->whereNull('digested_at')
                ->orderBy('created_at')
                ->get();

            if ($waiting->isEmpty() || blank($user->email)) {
                continue;
            }

            try {
                Mail::to($user->email)->send(new NoticeDigest($user, $waiting));
            } catch (Throwable $e) {
                // A digest that cannot be sent must not be marked as sent, or
                // it is lost rather than late.
                Log::warning('The digest could not be sent', ['user' => $user->id, 'error' => $e->getMessage()]);

                continue;
            }

            NoticeSent::whereIn('id', $waiting->pluck('id'))->update(['digested_at' => now()]);
            $sent++;
        }

        if ($sent > 0) {
            $this->components->info("{$sent} digests sent.");
        }

        return self::SUCCESS;
    }
}
