<?php

namespace App\Console\Commands;

use App\Services\HomeAssistant\Client;
use App\Services\HomeAssistant\HomeAssistant;
use App\Services\HomeAssistant\StateStore;
use Illuminate\Console\Command;
use Throwable;
use WebSocket\Client as WebSocketClient;

/**
 * Holds a websocket open to Home Assistant and keeps the state cache warm.
 *
 * Optional. Without it the wall polls the REST API every few seconds and is
 * never wrong for long; with it, tiles follow a physical light switch almost
 * immediately and the Pi is left alone in between.
 *
 * Long-running, so it is written to be killed and restarted at any moment:
 * everything it knows lives in the cache, and it re-seeds from /api/states on
 * every connect rather than assuming what it had before is still true.
 */
class ListenToHomeAssistant extends Command
{
    protected $signature = 'familyhub:ha-listen
        {--once : Connect, seed, and exit — for checking the credentials work}';

    protected $description = 'Follow Home Assistant state changes over the websocket';

    /** Seconds between reconnection attempts, lengthening while it stays down. */
    protected const BACKOFF = [1, 2, 5, 10, 30, 60];

    protected int $attempt = 0;

    public function handle(Client $client, HomeAssistant $ha): int
    {
        if (! $client->isConfigured()) {
            $this->error('No HA_URL and HA_TOKEN are set.');

            return self::FAILURE;
        }

        $this->info('Listening to '.$client->websocketUrl());

        do {
            try {
                $this->listen($client, $ha);

                $this->attempt = 0;
            } catch (Throwable $e) {
                $this->warn('Lost Home Assistant: '.$e->getMessage());

                // The cache keeps its five-minute life so the wall shows the
                // last known state rather than emptying while the Pi reboots.
                $this->pause();
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    protected function listen(Client $client, HomeAssistant $ha): void
    {
        $socket = new WebSocketClient($client->websocketUrl());
        $socket->setTimeout(60);
        $socket->connect();

        $store = new StateStore;

        try {
            $this->authenticate($socket, $client->token());

            // Seeded over REST rather than over the socket: it is the same
            // answer, and it keeps one shape of "all the states" in the app.
            $store->seed($ha->rawStates());
            $ha->remember($store->rows());

            $this->info("Connected. Holding {$store->count()} entities.");

            $socket->text(json_encode(['id' => 2, 'type' => 'subscribe_events', 'event_type' => 'state_changed']));

            if ($this->option('once')) {
                return;
            }

            $this->follow($socket, $store, $ha);
        } finally {
            $socket->close();
        }
    }

    protected function follow(WebSocketClient $socket, StateStore $store, HomeAssistant $ha): void
    {
        while (true) {
            $message = json_decode($socket->receive()->getContent(), true);

            if (is_array($message) && $store->apply($message)) {
                $ha->remember($store->rows());
            }
        }
    }

    /** HA asks before it says anything else. */
    protected function authenticate(WebSocketClient $socket, string $token): void
    {
        $hello = json_decode($socket->receive()->getContent(), true);

        if (($hello['type'] ?? null) !== 'auth_required') {
            throw new \RuntimeException('Home Assistant did not ask to authenticate.');
        }

        $socket->text(json_encode(['type' => 'auth', 'access_token' => $token]));

        $reply = json_decode($socket->receive()->getContent(), true);

        if (($reply['type'] ?? null) !== 'auth_ok') {
            throw new \RuntimeException(
                'Home Assistant refused the token: '.($reply['message'] ?? 'no reason given')
            );
        }
    }

    protected function pause(): void
    {
        $seconds = self::BACKOFF[min($this->attempt, count(self::BACKOFF) - 1)];

        $this->attempt++;

        if (! $this->option('once')) {
            sleep($seconds);
        }
    }
}
