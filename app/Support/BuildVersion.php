<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * A short string identifying the deployed build.
 *
 * Prefers the Vite manifest's content hashes, because those change exactly when
 * the front-end assets change — which is what the wall display actually needs
 * to know. Falls back to the git commit, then to a file mtime, so a deploy
 * without either still produces something that moves.
 */
class BuildVersion
{
    public const CACHE_KEY = 'familyhub.build-version';

    public static function current(): string
    {
        // Short-lived on purpose. Computing it is a small file read and a hash,
        // and a longer window would make deploy detection depend on the deploy
        // remembering to clear the cache.
        return Cache::remember(self::CACHE_KEY, now()->addSeconds(60), fn () => self::compute());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The directory the *live* release is served from.
     *
     * Under zero-downtime deploys the app lives in `releases/<timestamp>` with
     * a `current` symlink pointing at whichever one is live. PHP resolves
     * symlinks in __DIR__, so `base_path()` inside a long-running process is
     * pinned to the release it started in — it would read its own manifest for
     * ever and never notice a deploy at all, which is precisely the thing this
     * class exists to notice.
     *
     * So: an explicit path if one is configured, otherwise the `current`
     * symlink beside our own release, otherwise wherever we are.
     */
    public static function livePath(): string
    {
        if ($configured = config('familyhub.live_path')) {
            return rtrim((string) $configured, '/');
        }

        $base = rtrim(base_path(), '/');

        if (preg_match('#^(.*)/releases/[^/]+$#', $base, $m) && is_dir($m[1].'/current')) {
            return $m[1].'/current';
        }

        return $base;
    }

    /** A path inside the live release, however this process was started. */
    protected static function liveFile(string $path): string
    {
        return self::livePath().'/'.ltrim($path, '/');
    }

    /**
     * Where the built manifest is.
     *
     * A public path somebody has deliberately moved is respected as-is; the
     * default one is resolved through the deploy symlink rather than through
     * this process's own release.
     */
    protected static function manifestPath(): string
    {
        $public = rtrim(public_path(), '/');
        $default = rtrim(base_path(), '/').'/public';

        return ($public === $default ? self::liveFile('public') : $public).'/build/manifest.json';
    }

    protected static function compute(): string
    {
        return self::fromViteManifest()
            ?? self::fromGit()
            ?? self::fromApplicationFiles();
    }

    protected static function fromViteManifest(): ?string
    {
        $manifest = self::manifestPath();

        if (! is_file($manifest)) {
            return null;
        }

        $contents = file_get_contents($manifest);

        return $contents === false ? null : 'v'.substr(hash('sha256', $contents), 0, 12);
    }

    protected static function fromGit(): ?string
    {
        // Forge writes the deployed SHA here; a bare checkout has HEAD instead.
        foreach (['REVISION', '.git/HEAD'] as $path) {
            $file = self::liveFile($path);

            if (! is_file($file)) {
                continue;
            }

            $contents = trim((string) file_get_contents($file));

            if (str_starts_with($contents, 'ref: ')) {
                $ref = self::liveFile('.git/'.trim(substr($contents, 5)));
                $contents = is_file($ref) ? trim((string) file_get_contents($ref)) : '';
            }

            if (preg_match('/^[0-9a-f]{7,40}$/i', $contents)) {
                return 'g'.substr($contents, 0, 12);
            }
        }

        return null;
    }

    /** Last resort: something that at least changes when the app is redeployed. */
    protected static function fromApplicationFiles(): string
    {
        $lock = self::liveFile('composer.lock');
        $app = self::liveFile('app');

        $stamp = max(
            is_file($lock) ? filemtime($lock) : 0,
            is_dir($app) ? filemtime($app) : 0,
        );

        return 't'.($stamp ?: 0);
    }
}
