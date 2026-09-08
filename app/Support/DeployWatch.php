<?php

namespace App\Support;

use Throwable;

/**
 * Notices that the code underneath a long-running process has changed.
 *
 * A daemon started before a deploy keeps running the old code until something
 * restarts it — including the old version of whatever bug the deploy fixed.
 * Rather than have deploys remember to restart every daemon by name, each one
 * watches for the change itself and exits cleanly; the process manager brings
 * it back on the new code.
 *
 * Uses the same build id the wall display reloads on, so "deployed" means one
 * thing across the whole app.
 */
class DeployWatch
{
    protected function __construct(protected string $startedOn) {}

    public static function start(): self
    {
        return new self(self::read());
    }

    /** The build this process was started on. */
    public function startedOn(): string
    {
        return $this->startedOn;
    }

    public function hasChanged(): bool
    {
        $now = self::read();

        // An unreadable build id means something is odd about the filesystem,
        // not that a deploy happened. Restarting on it would be a loop.
        return $now !== '' && $now !== $this->startedOn;
    }

    protected static function read(): string
    {
        try {
            // A long-running process accumulates stat results, so filemtime
            // would keep reporting the state of the world at boot. The `true`
            // matters more: it clears the realpath cache, without which the
            // `current` symlink keeps resolving to the release this process
            // started in and a deploy is invisible.
            clearstatcache(true);

            return BuildVersion::current();
        } catch (Throwable) {
            return '';
        }
    }
}
