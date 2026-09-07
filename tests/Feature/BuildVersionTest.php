<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\User;
use App\Support\BuildVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BuildVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BuildVersion::forget();
        Household::factory()->create();
    }

    #[Test]
    public function the_version_endpoint_is_reachable_without_signing_in(): void
    {
        // The display has to read this before Livewire boots.
        $this->get('/version')
            ->assertOk()
            ->assertJsonStructure(['version']);
    }

    #[Test]
    public function the_version_endpoint_is_never_cached(): void
    {
        $response = $this->get('/version');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function the_version_is_a_non_empty_string(): void
    {
        $version = $this->get('/version')->json('version');

        $this->assertIsString($version);
        $this->assertNotSame('', $version);
    }

    #[Test]
    public function the_version_is_stable_between_calls(): void
    {
        $this->assertSame(
            $this->get('/version')->json('version'),
            $this->get('/version')->json('version'),
        );
    }

    #[Test]
    public function it_changes_when_the_built_assets_change(): void
    {
        $before = BuildVersion::current();

        BuildVersion::forget();
        Cache::flush();

        // Simulate a deploy by changing the manifest the version is derived from.
        $manifest = public_path('build/manifest.json');
        $original = file_get_contents($manifest);

        try {
            file_put_contents($manifest, $original."\n");
            BuildVersion::forget();

            $this->assertNotSame($before, BuildVersion::current());
        } finally {
            file_put_contents($manifest, $original);
            BuildVersion::forget();
        }
    }

    #[Test]
    public function the_display_page_embeds_the_version(): void
    {
        $this->withCookie(\App\Http\Middleware\EnsureDisplayToken::COOKIE, 'test-display-token')
            ->get('/display')
            ->assertOk()
            ->assertSee('name="build-version"', escape: false)
            ->assertSee(BuildVersion::current());
    }

    #[Test]
    public function the_display_page_points_at_the_version_endpoint(): void
    {
        $this->withCookie(\App\Http\Middleware\EnsureDisplayToken::COOKIE, 'test-display-token')
            ->get('/display')
            ->assertSee('data-endpoint="'.route('version').'"', escape: false)
            ->assertSee('data-idle-ms="30000"', escape: false)
            ->assertSee('data-daily-at="03:45"', escape: false);
    }

    #[Test]
    public function the_build_is_shown_in_the_admin_about_section(): void
    {
        $this->actingAs(User::factory()->create(['household_id' => Household::current()->id]));

        Livewire::test('admin.settings')
            ->assertSee('About')
            ->assertSee('Build')
            ->assertSee(BuildVersion::current());
    }
}
