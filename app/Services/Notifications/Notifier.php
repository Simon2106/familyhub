<?php

namespace App\Services\Notifications;

use App\Models\Household;
use App\Models\NoticeSent;
use App\Models\PushSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Telling somebody something, once.
 *
 * The ledger row is written before anything is sent, which is what makes
 * "never twice" true whatever happens next — a push that fails, a quiet hour
 * that holds it back, a digest that carries it instead are all the same row.
 *
 * Quiet hours are the household's own dark-mode window rather than a separate
 * setting: the hours the wall dims itself are the hours nobody wants a phone
 * buzzing either, and one of them being right and the other wrong is worse
 * than either.
 */
class Notifier
{
    public function __construct(protected NotificationSettings $settings) {}

    public function isConfigured(): bool
    {
        return filled(config('familyhub.push.public_key'))
            && filled(config('familyhub.push.private_key'));
    }

    /**
     * Tell them, or hold it for the digest.
     *
     * @return bool whether anything new was recorded at all
     */
    public function tell(User $user, Notice $notice, ?CarbonImmutable $now = null): bool
    {
        if (! $this->settings->wants($user, $notice->trigger)) {
            return false;
        }

        // The ledger first. A duplicate is a no-op rather than a second buzz.
        $record = NoticeSent::firstOrCreate(
            ['user_id' => $user->id, 'trigger' => $notice->trigger, 'subject' => $notice->subject],
            ['title' => $notice->title, 'body' => $notice->body, 'url' => $notice->url],
        );

        if (! $record->wasRecentlyCreated) {
            return false;
        }

        if ($this->isQuiet($user, $now)) {
            // Held. The digest will carry it in the morning rather than a
            // phone lighting up a bedroom.
            return true;
        }

        if ($this->push($user, $notice)) {
            $record->forceFill(['sent_at' => now()])->save();
        }

        return true;
    }

    /** Whether now is inside the hours the household has its wall dimmed. */
    public function isQuiet(User $user, ?CarbonImmutable $now = null): bool
    {
        $household = $user->household ?? Household::current();
        $dark = $household->darkMode();
        $now ??= $household->nowLocal();

        $minutes = (int) $now->format('G') * 60 + (int) $now->format('i');
        $from = $this->minutes($dark['start']);
        $to = $this->minutes($dark['end']);

        // A window that crosses midnight is the normal case here.
        return $from <= $to
            ? $minutes >= $from && $minutes < $to
            : $minutes >= $from || $minutes < $to;
    }

    protected function minutes(string $time): int
    {
        [$hours, $minutes] = array_pad(explode(':', $time), 2, '0');

        return (int) $hours * 60 + (int) $minutes;
    }

    /**
     * Send to every device this person has, dropping the ones that have gone.
     *
     * A browser that has been reinstalled leaves an endpoint behind that will
     * answer 410 for ever; keeping it would mean every future send carries a
     * failure with it.
     */
    public function push(User $user, Notice $notice): bool
    {
        $subscriptions = $user->pushSubscriptions()->get();

        if ($subscriptions->isEmpty() || ! $this->isConfigured()) {
            return false;
        }

        try {
            $push = new WebPush([
                'VAPID' => [
                    'subject' => config('familyhub.push.subject') ?: config('app.url'),
                    'publicKey' => config('familyhub.push.public_key'),
                    'privateKey' => config('familyhub.push.private_key'),
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('Web push could not be set up', ['error' => $e->getMessage()]);

            return false;
        }

        $payload = json_encode([
            'title' => $notice->title,
            'body' => $notice->body,
            'url' => $notice->url,
            'tag' => $notice->trigger,
        ]);

        foreach ($subscriptions as $subscription) {
            try {
                $push->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'keys' => ['p256dh' => $subscription->p256dh, 'auth' => $subscription->auth],
                    ]),
                    $payload,
                );
            } catch (Throwable $e) {
                Log::warning('A device could not be queued', ['error' => $e->getMessage()]);
            }
        }

        $sent = false;

        try {
            foreach ($push->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent = true;

                    continue;
                }

                // 404 and 410 mean the browser is gone for good.
                if (in_array($report->getResponse()?->getStatusCode(), [404, 410], true)) {
                    PushSubscription::where('endpoint_hash', PushSubscription::hash($report->getEndpoint()))->delete();
                }
            }
        } catch (Throwable $e) {
            Log::warning('Web push failed', ['error' => $e->getMessage()]);
        }

        if ($sent) {
            $user->pushSubscriptions()->update(['last_sent_at' => now()]);
        }

        return $sent;
    }
}
