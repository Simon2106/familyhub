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

    /**
     * Whether an Echo will actually say it, as opposed to the wall alone.
     *
     * A service is all it takes. Alexa Media Player exposes one service
     * per device — notify.alexa_media_kitchen_echo_show_8_3rd_gen — plus
     * notify.alexa_media_everywhere, and for those the service *is* the
     * target. Requiring a target as well is why this said no Alexa was set
     * up on a house that had one, and why the wall never called HA at all.
     */
    public function speaks(Household $household): bool
    {
        return filled($household->announceConfig()['service']);
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

        $why = $this->speak($household, $message);

        $result = $this->result(true, $why === null, $message, $who, $now, $why);

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
    /**
     * Hand it to Home Assistant.
     *
     * The payload is what the Alexa Media Player integration wants: the
     * words, and data.type = announce to make an Echo chime and speak
     * rather than light its ring. A target is sent only where one was
     * chosen — a per-device service rejects one, because it already knows
     * which device it is.
     *
     * Returns the reason it failed, or null when it worked. The reason is
     * carried back rather than only logged: "it did nothing" is the whole
     * complaint, and /admin has to be able to say why.
     */
    protected function speak(Household $household, string $message): ?string
    {
        $config = $household->announceConfig();

        if (! filled($config['service'])) {
            return 'Shown on the wall. No Alexa service is set up, so nothing was said out loud.';
        }

        if (! $this->ha->isConfigured()) {
            return 'Shown on the wall. Home Assistant is not connected — set HA_URL and HA_TOKEN.';
        }

        $payload = ['message' => $message, 'data' => ['type' => 'announce']];

        if ($config['targets'] !== []) {
            $payload['target'] = $config['targets'];
        }

        try {
            $this->ha->notify($config['service'], $payload);

            return null;
        } catch (Throwable $e) {
            Log::warning('An announcement could not be spoken', [
                'service' => $config['service'],
                'error' => $e->getMessage(),
            ]);

            return 'Shown on the wall. '.$config['service'].' did not answer: '.$e->getMessage();
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
