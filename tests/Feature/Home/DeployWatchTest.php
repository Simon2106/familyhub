<?php

namespace Tests\Feature\Home;

use App\Support\BuildVersion;
use App\Support\DeployWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Noticing that the code underneath a long-running process has changed.
 *
 * A daemon started before a deploy keeps running the old code — including the
 * old version of whatever the deploy fixed — until something restarts it.
 */
class DeployWatchTest extends TestCase
{
    use RefreshDatabase;

    protected string $public;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway public directory, so a test about deploys cannot delete
        // the manifest the app is actually running on.
        $this->public = storage_path('framework/testing/public-'.uniqid());

        mkdir($this->public.'/build', recursive: true);
        $this->app->usePublicPath($this->public);

        $this->deploy();
    }

    protected function tearDown(): void
    {
        BuildVersion::forget();

        foreach (glob($this->public.'/build/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->public.'/build');
        @rmdir($this->public);

        parent::tearDown();
    }

    /** Change what the build id is computed from. */
    protected function deploy(): void
    {
        file_put_contents(
            $this->public.'/build/manifest.json',
            json_encode(['deployed-at' => uniqid('', true)]),
        );

        BuildVersion::forget();
    }

    #[Test]
    public function nothing_has_changed_when_nothing_has_changed(): void
    {
        $watch = DeployWatch::start();

        $this->assertFalse($watch->hasChanged());
        $this->assertFalse($watch->hasChanged(), 'Asking twice must not invent a change.');
    }

    #[Test]
    public function it_remembers_the_build_it_started_on(): void
    {
        $this->assertSame(BuildVersion::current(), DeployWatch::start()->startedOn());
    }

    #[Test]
    public function a_new_build_is_noticed(): void
    {
        $watch = DeployWatch::start();

        $this->deploy();

        $this->assertTrue($watch->hasChanged());
    }

    #[Test]
    public function it_keeps_saying_so_once_it_has_noticed(): void
    {
        $watch = DeployWatch::start();
        $this->deploy();

        $this->assertTrue($watch->hasChanged());
        $this->assertTrue($watch->hasChanged());
    }

    #[Test]
    public function the_cached_build_id_is_not_what_it_compares_against(): void
    {
        // BuildVersion caches for a minute, so a watch that read through a
        // stale cache would sit on old code for as long as the cache lived.
        // The point of this test is that forgetting the cache is enough to
        // surface a change — no deploy hook required.
        $watch = DeployWatch::start();

        $this->deploy();

        $this->assertTrue($watch->hasChanged());
    }
}
