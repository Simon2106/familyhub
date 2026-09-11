<?php

namespace Tests\Feature\Display;

use App\Models\Household;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nothing on the wall may link off the wall.
 *
 * A kiosk has no address bar, no back button and no keyboard. One tap on a
 * link to /admin strands it on a login screen with no way out but an SSH
 * session — which is how this test came to exist.
 */
class WallGoesNowhereTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'familyhub.photos.disk' => 'public',
            'familyhub.photos.path' => 'photos',
            'familyhub.display.token' => 'wall-token',
        ]);

        $this->household = Household::factory()->create();
        User::factory()->create(['household_id' => $this->household->id]);
    }

    /** @return list<string> every href the wall renders */
    protected function anchors(): array
    {
        $html = $this->withoutVite()->get('/display?token=wall-token')->assertOk()->getContent();

        preg_match_all('/<a\b[^>]*\bhref=("|\')(.*?)\1/i', $html, $found);

        return $found[2];
    }

    protected function leavesTheWall(string $href): bool
    {
        $href = html_entity_decode(trim($href));

        if ($href === '' || str_starts_with($href, '#')) {
            return false;
        }

        $path = parse_url($href, PHP_URL_PATH) ?: '';
        $host = parse_url($href, PHP_URL_HOST);

        // Another host is off the wall whatever its path says.
        if ($host !== null && $host !== parse_url(config('app.url'), PHP_URL_HOST)) {
            return true;
        }

        return $path !== '/display' && ! str_starts_with($path, '/display/');
    }

    #[Test]
    public function no_link_on_the_wall_points_off_the_wall(): void
    {
        $offWall = array_values(array_filter($this->anchors(), $this->leavesTheWall(...)));

        $this->assertSame([], $offWall, 'These would strand the kiosk: '.implode(', ', $offWall));
    }

    /**
     * The empty state is where this went wrong, so it is checked with the
     * board in the state that produced the link.
     */
    #[Test]
    public function the_photo_empty_state_does_not_link_to_settings(): void
    {
        $this->assertSame(0, Photo::count(), 'This is the case that rendered the link.');

        $offWall = array_values(array_filter($this->anchors(), $this->leavesTheWall(...)));

        $this->assertSame([], $offWall);

        // It still has to say where to go, in words.
        $html = $this->withoutVite()->get('/display?token=wall-token')->getContent();

        $this->assertStringContainsString('Settings live on a phone', $html);
        $this->assertStringNotContainsString(route('admin'), $html);
    }

    /** With photographs on it, the grid must not introduce one either. */
    #[Test]
    public function a_wall_with_photographs_still_links_nowhere(): void
    {
        foreach (range(1, 3) as $n) {
            Photo::create([
                'household_id' => $this->household->id,
                'source' => 'upload',
                'disk' => 'public',
                'path' => 'photos/one-'.$n.'.jpg',
                'taken_at' => now()->subDays($n),
            ]);
        }

        $this->assertSame([], array_values(array_filter($this->anchors(), $this->leavesTheWall(...))));
    }

    #[Test]
    public function the_wall_carries_the_address_it_should_return_to(): void
    {
        $html = $this->withoutVite()->get('/display?token=wall-token')->getContent();

        $this->assertStringContainsString('data-display-url=', $html);
        $this->assertStringContainsString('data-kiosk', $html);
    }
}
