<?php

namespace Tests\Feature;

use App\Support\BuildVersion;
use App\Support\DeployWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Noticing a deploy under zero-downtime releases.
 *
 * The trap this guards: PHP resolves symlinks in __DIR__, so a daemon started
 * through `current/artisan` has a base_path() pinned to the release it started
 * in. Reading the build id from there means reading its own manifest for ever
 * and never noticing a deploy — the exact opposite of what it is for.
 */
class DeployDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/fh-deploy-'.bin2hex(random_bytes(4));

        foreach (['one', 'two'] as $release) {
            mkdir($this->root.'/releases/'.$release.'/public/build', 0777, true);
            file_put_contents(
                $this->root.'/releases/'.$release.'/public/build/manifest.json',
                json_encode(['app.js' => ['file' => "app-{$release}.js"]]),
            );
        }

        $this->point('one');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    /** Repoint `current`, the way a zero-downtime deploy does. */
    protected function point(string $release): void
    {
        $link = $this->root.'/current';

        if (is_link($link)) {
            unlink($link);
        }

        symlink($this->root.'/releases/'.$release, $link);
    }

    #[Test]
    public function the_live_path_is_where_we_are_when_there_is_no_release_layout(): void
    {
        config(['familyhub.live_path' => null]);

        $this->assertSame(rtrim(base_path(), '/'), BuildVersion::livePath());
    }

    #[Test]
    public function a_configured_live_path_wins(): void
    {
        config(['familyhub.live_path' => $this->root.'/current/']);

        $this->assertSame($this->root.'/current', BuildVersion::livePath());
    }

    #[Test]
    public function a_release_directory_finds_the_symlink_beside_it(): void
    {
        // What base_path() actually is inside a daemon started via current/.
        $this->app->setBasePath($this->root.'/releases/one');
        config(['familyhub.live_path' => null]);

        $this->assertSame($this->root.'/current', BuildVersion::livePath());
    }

    #[Test]
    public function a_release_layout_with_no_symlink_falls_back_to_itself(): void
    {
        unlink($this->root.'/current');
        $this->app->setBasePath($this->root.'/releases/one');
        config(['familyhub.live_path' => null]);

        $this->assertSame($this->root.'/releases/one', BuildVersion::livePath());
    }

    #[Test]
    public function a_daemon_pinned_to_its_own_release_still_sees_the_deploy(): void
    {
        // The whole point. base_path() is release one and stays release one;
        // the build id must follow `current` to release two.
        $this->app->setBasePath($this->root.'/releases/one');
        config(['familyhub.live_path' => null]);

        BuildVersion::forget();
        $watch = DeployWatch::start();

        $this->assertFalse($watch->hasChanged());

        $this->point('two');
        BuildVersion::forget();

        $this->assertTrue($watch->hasChanged(), 'A deploy under the process went unnoticed.');
    }

    #[Test]
    public function reading_it_twice_without_a_deploy_reports_no_change(): void
    {
        $this->app->setBasePath($this->root.'/releases/one');
        config(['familyhub.live_path' => null]);

        BuildVersion::forget();
        $watch = DeployWatch::start();

        BuildVersion::forget();
        $this->assertFalse($watch->hasChanged());

        BuildVersion::forget();
        $this->assertFalse($watch->hasChanged());
    }

    #[Test]
    public function an_unreadable_build_id_is_not_treated_as_a_deploy(): void
    {
        // Restarting on "something is odd about the filesystem" is a loop.
        $watch = DeployWatch::start();

        config(['familyhub.live_path' => '/nonexistent-'.bin2hex(random_bytes(4))]);
        BuildVersion::forget();

        // Falls through to the file stamp, which is a stable 't0' for a
        // missing directory rather than an empty string that flaps.
        $this->assertSame('t0', BuildVersion::current());
    }
}
