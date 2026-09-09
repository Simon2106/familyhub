<?php

namespace App\Console\Commands;

use App\Events\HomeStateChanged;
use App\Services\HomeAssistant\Client;
use App\Services\HomeAssistant\HomeAssistant;
use App\Services\HomeAssistant\StateStore;
use App\Support\DeployWatch;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;
use WebSocket\Client as WebSocketClient;
use WebSocket\Exception\ConnectionTimeoutException;

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
 *
 * It also stops itself when the code underneath it changes. A daemon started
 * before a deploy otherwise keeps running the old code indefinitely — including
 * the old version of whatever the deploy fixed — and exiting cleanly lets the
 * process manager bring it back on the new one.
 */
class ListenToHomeAssistant extends Command
{
    protected $signature = 'familyhub:ha-listen
        {--once : Connect, seed, and exit — for checking the credentials work}';

    protected $description = 'Follow Home Assistant state changes over the websocket';

    /** Seconds between reconnection attempts, lengthening while it stays down. */
    protected const BACKOFF = [1, 2, 5, 10, 30, 60];

    /**
     * How long a quiet socket waits before the loop comes up for air.
     *
     * This is the deploy check's heartbeat as much as a read timeout: a house
     * where nothing is switched on all night would otherwise never look.
     */
    protected const IDLE_SECONDS = 15;

    protected int $attempt = 0;

    protected DeployWatch $deploy;

    /** Set once new code is on disk, so every loop unwinds and the process ends. */
    protected bool $superseded = false;

    /** Whether a connection was ever established, which is what --once reports on. */
    protected bool $connected = false;

    /** Set by SIGTERM, so the loop finishes what it is doing and stops. */
    protected bool $stopping = false;

    /**
     * The least time between nudges to the wall.
     *
     * A house coming to life in the morning produces a burst of state changes,
     * and the wall does not need one round trip per light. It re-reads
     * everything it shows in a single local cache read, so one nudge covers
     * them all.
     */
    protected const BROADCAST_EVERY_MS = 400;

    protected float $lastBroadcastAt = 0;

    public function handle(Client $client, HomeAssistant $ha): int
    {
        if (! $client->isConfigured()) {
            $this->error('No HA_URL and HA_TOKEN are set.');

            return self::FAILURE;
        }

        $this->deploy = DeployWatch::start();
        $this->listenForSignals();

        $this->info('Listening to '.$client->websocketUrl().' on build '.$this->deploy->startedOn());

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
        } while (! $this->option('once') && ! $this->superseded && ! $this->stopping);

        if ($this->superseded) {
            $this->info('New code deployed. Stopping so it can be restarted on it.');
        }

        if ($this->stopping) {
            $this->info('Asked to stop. Closing the connection and exiting.');
        }

        // A --once run exists to prove the credentials work, so it has to fail
        // when they do not; the long-running form is only ever stopped on
        // purpose, and reports that as success.
        return $this->option('once') && ! $this->connected ? self::FAILURE : self::SUCCESS;
    }

    protected function listen(Client $client, HomeAssistant $ha): void
    {
        $socket = new WebSocketClient($client->websocketUrl());
        $socket->setTimeout(self::IDLE_SECONDS);
        $socket->connect();

        $store = new StateStore;

        try {
            $this->authenticate($socket, $client->token());

            // Seeded over REST rather than over the socket: it is the same
            // answer, and it keeps one shape of "all the states" in the app.
            $store->seed($ha->rawStates());
            $ha->remember($store->rows());
            $this->nudge(force: true);

            $this->connected = true;

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
        while (! $this->superseded()) {
            try {
                $message = json_decode($socket->receive()->getContent(), true);
            } catch (ConnectionTimeoutException) {
                // Nothing happened in the house. Not a failure — just the
                // chance to look at whether the code has moved underneath us.
                if (! $socket->isConnected()) {
                    throw new RuntimeException('The connection went away while it was quiet.');
                }

                continue;
            }

            if (is_array($message) && $store->apply($message)) {
                $ha->remember($store->rows());
                $this->nudge();
            }
        }
    }

    /**
     * Tell the wall to look again.
     *
     * Never fatal: a Reverb that is down or not configured at all must not stop
     * the listener keeping the cache warm, because the wall's poll is still
     * reading from it. Broadcasting is the fast path, not the only one.
     */
    protected function nudge(bool $force = false): void
    {
        $now = microtime(true) * 1000;

        if (! $force && $now - $this->lastBroadcastAt < self::BROADCAST_EVERY_MS) {
            return;
        }

        $this->lastBroadcastAt = $now;

        // Guarded inside nudge(), so the daemon cannot be brought down by a
        // websocket nobody is listening on.
        HomeStateChanged::nudge();
    }

    /**
     * Stop at the next safe moment rather than mid-frame.
     *
     * Supervisor sends SIGTERM and waits before resorting to SIGKILL. Taking
     * the hint means the websocket is closed politely and the cache is left
     * whole, rather than the process being shot while half-way through
     * writing it.
     */
    protected function listenForSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        foreach ([SIGTERM, SIGINT] as $signal) {
            $this->trap($signal, function () {
                $this->stopping = true;
            });
        }
    }

    /** Cached against the deploy watch so this is a cheap thing to ask often. */
    protected function superseded(): bool
    {
        return $this->superseded
            = $this->superseded || $this->stopping || $this->deploy->hasChanged();
    }

    /** HA asks before it says anything else. */
    protected function authenticate(WebSocketClient $socket, string $token): void
    {
        $hello = json_decode($socket->receive()->getContent(), true);

        if (($hello['type'] ?? null) !== 'auth_required') {
            throw new RuntimeException('Home Assistant did not ask to authenticate.');
        }

        $socket->text(json_encode(['type' => 'auth', 'access_token' => $token]));

        $reply = json_decode($socket->receive()->getContent(), true);

        if (($reply['type'] ?? null) !== 'auth_ok') {
            throw new RuntimeException(
                'Home Assistant refused the token: '.($reply['message'] ?? 'no reason given')
            );
        }
    }

    /**
     * Wait before trying again, in slices.
     *
     * A minute of backoff must not be a minute of ignoring a deploy: an HA that
     * has been down all morning is exactly when a fix is most likely to be on
     * its way.
     */
    protected function pause(): void
    {
        $seconds = self::BACKOFF[min($this->attempt, count(self::BACKOFF) - 1)];

        $this->attempt++;

        if ($this->option('once')) {
            return;
        }

        for ($slept = 0; $slept < $seconds; $slept++) {
            if ($this->superseded()) {
                return;
            }

            sleep(1);
        }
    }
}
