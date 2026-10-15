<?php

namespace App\Services\Announce;

use App\Models\Household;
use App\Models\Member;
use App\Services\HomeAssistant\HomeAssistant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shouting up the stairs, without the stairs.
 *
 * Home Assistant's Alexa Media Player integration exposes a notify service
 * per device; calling it with an announce message is what makes an Echo say
 * something out loud. Which service and which devices are chosen in /admin,
 * because nothing about either is guessable and a hard-coded entity id is a
 * feature that works in exactly one house.
 *
 * Where that is not set up, the wall says it itself — the banner goes up
 * either way, and /admin says plainly that nothing will be spoken.
 */
class Announcer
{
    /** One announcement per this many seconds. */
    public const EVERY_SECONDS = 10;

    /** How long the banner stays on the wall. */
    public const BANNER_SECONDS = 20;

    /** How many are kept for /admin to show. */
    public const LOG_LENGTH = 50;

    public const RATE_KEY = 'announce:last';

    public const LOG_KEY = 'announce:log';

    public const LOG_TTL_DAYS = 7;

    public function __construct(protected HomeAssistant $ha) {}

    /** Whether an Echo will actually say it, as opposed to the wall alone. */
    public function speaks(Household $household): bool
    {
        $config = $household->announceConfig();

        return filled($config['service']) && $config['targets'] !== [];
    }

    /**
     * Say something.
     *
     * Returns what happened, because the wall shows it: the message, who
     * sent it, whether an Echo carried it, and why not if it did not.
     *
     * @return array{sent: bool, spoken: bool, message: string, by: string, at: string, reason: ?string}
     */
    public function announce(Household $household, string $message, ?Member $by = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $message = mb_substr(trim($message), 0, 200);
        $who = $by?->name ?? 'Wall';

        if ($message === '') {
            return $this->result(false, false, $message, $who, $now, 'There was nothing to say.');
        }

        if ($this->tooSoon($now)) {
            // A button on a wall at a child's height is a button that gets
            // pressed eleven times.
            return $this->result(false, false, $message, $who, $now, 'Hold on a moment — one at a time.');
        }

        Cache::put(self::RATE_KEY, $now->getTimestamp(), now()->addMinutes(5));

        $spoken = $this->speak($household, $message);

        $result = $this->result(
            true,
            $spoken,
            $message,
            $who,
            $now,
            $spoken ? null : 'Shown on the wall. No Alexa is set up, so nothing was said out loud.',
        );

        $this->remember($result);

        return $result;
    }

    /** Whether the last one was too recent. */
    public function tooSoon(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $last = Cache::get(self::RATE_KEY);

        return is_numeric($last) && $now->getTimestamp() - (int) $last < self::EVERY_SECONDS;
    }

    /**
     * Hand it to Home Assistant.
     *
     * False rather than an exception when it cannot: the message is already
     * on the wall, and a kitchen screen that throws because a speaker is
     * unplugged is worse than one that says it quietly.
     */
    protected function speak(Household $household, string $message): bool
    {
        if (! $this->speaks($household) || ! $this->ha->isConfigured()) {
            return false;
        }

        $config = $household->announceConfig();

        try {
            $this->ha->notify($config['service'], [
                'message' => $message,
                'target' => $config['targets'],
                'data' => ['type' => 'announce'],
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('An announcement could not be spoken', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The last fifty, newest first.
     *
     * In the cache rather than a table: this is "did that work", not a
     * record anybody asked us to keep of what is shouted up the stairs.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(): array
    {
        $log = Cache::get(self::LOG_KEY, []);

        return is_array($log) ? $log : [];
    }

    /** @param array<string, mixed> $result */
    protected function remember(array $result): void
    {
        Cache::put(
            self::LOG_KEY,
            array_slice([$result, ...$this->recent()], 0, self::LOG_LENGTH),
            now()->addDays(self::LOG_TTL_DAYS),
        );
    }

    /** @return array{sent: bool, spoken: bool, message: string, by: string, at: string, reason: ?string} */
    protected function result(bool $sent, bool $spoken, string $message, string $by, CarbonImmutable $at, ?string $reason): array
    {
        return [
            'sent' => $sent,
            'spoken' => $spoken,
            'message' => $message,
            'by' => $by,
            'at' => $at->toIso8601String(),
            'reason' => $reason,
        ];
    }
}
