<?php

namespace Tests\Feature\Photos;

use App\Models\Household;
use App\Models\Photo;
use App\Models\User;
use App\Services\PhotoLibrary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The photo library, as a thing somebody looks through.
 *
 * The grid was already here; what it could not do was open one, say "more of
 * this one", or take one off the wall without going to find it again.
 */
class PhotoLibraryPageTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['familyhub.photos.disk' => 'public', 'familyhub.photos.path' => 'photos']);

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
    }

    protected function photo(array $attributes = []): Photo
    {
        static $n = 0;

        $n++;

        return Photo::create($attributes + [
            'household_id' => $this->household->id,
            'source' => 'upload',
            'disk' => 'public',
            'path' => 'photos/one-'.$n.'.jpg',
            'taken_at' => now()->subDays($n),
        ]);
    }

    /* ------------------------------ the album --------------------------- */

    #[Test]
    public function it_says_where_the_photographs_come_from_and_when_they_last_came(): void
    {
        $this->household->setPhotoAlbumUrl('https://www.icloud.com/sharedalbum/#B0abcdef');
        $this->household->recordPhotoSync('Family 2026');

        Livewire::test('photos.page')
            ->assertSee('Family 2026')
            ->assertSee('Last read');
    }

    #[Test]
    public function an_album_with_no_name_is_still_called_something(): void
    {
        Livewire::test('photos.page')
            ->assertSee('the shared album')
            ->assertSee('Not read yet');
    }

    /** "It has not worked since Tuesday" is the thing worth seeing here. */
    #[Test]
    public function a_failed_sync_says_so_on_the_page(): void
    {
        $this->household->recordPhotoSync(error: 'That album could not be reached.');

        Livewire::test('photos.page')->assertSee('That album could not be reached.');
    }

    #[Test]
    public function sync_now_says_so_when_there_is_no_album_to_read(): void
    {
        Livewire::test('photos.page')
            ->call('syncNow')
            ->assertSee('No shared album is set up yet');
    }

    /* ------------------------------ favourites -------------------------- */

    #[Test]
    public function a_photograph_can_be_made_a_favourite_and_unmade(): void
    {
        $photo = $this->photo();

        Livewire::test('photos.page')->call('toggleFavourite', $photo->id);
        $this->assertTrue($photo->fresh()->is_favourite);

        Livewire::test('photos.page')->call('toggleFavourite', $photo->id);
        $this->assertFalse($photo->fresh()->is_favourite);
    }

    /**
     * More often, not twice in a row.
     *
     * A favourite earns a second place in the running order; what matters is
     * that the two are not next to each other, because "more often" to
     * somebody watching means it comes round again sooner.
     */
    #[Test]
    public function a_favourite_comes_round_more_often_than_the_rest(): void
    {
        foreach (range(1, 12) as $ignored) {
            $this->photo();
        }

        $favourite = $this->photo(['is_favourite' => true]);

        $showing = app(PhotoLibrary::class)->photos(20, $this->household);

        $positions = $showing->keys()->filter(
            fn ($at) => $showing[$at]->id === $favourite->id
        )->values();

        $this->assertCount(2, $positions, 'It appears twice in a showing of the rest once.');
        $this->assertGreaterThan(1, $positions[1] - $positions[0], 'And never next to itself.');
    }

    #[Test]
    public function a_showing_with_no_favourites_is_left_exactly_as_it_was(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->photo();
        }

        $this->assertSame(5, app(PhotoLibrary::class)->photos(20, $this->household)->count());
    }

    #[Test]
    public function a_hidden_favourite_is_still_hidden(): void
    {
        $this->photo(['is_favourite' => true, 'is_hidden' => true]);
        $this->photo();

        $showing = app(PhotoLibrary::class)->photos(20, $this->household);

        $this->assertCount(1, $showing);
        $this->assertFalse($showing->first()->is_favourite);
    }

    /* ------------------------------- hiding ----------------------------- */

    /**
     * A long press on a screen full of pictures is easy to do by accident.
     */
    #[Test]
    public function hiding_offers_a_way_back(): void
    {
        $photo = $this->photo();

        $component = Livewire::test('photos.page')
            ->call('hide', $photo->id)
            ->assertSet('undoHide', $photo->id)
            ->assertSee('Hidden from the wall.');

        $this->assertTrue($photo->fresh()->is_hidden);

        $component->call('undoLastHide')->assertSet('undoHide', null);

        $this->assertFalse($photo->fresh()->is_hidden);
    }

    #[Test]
    public function the_way_back_can_be_waved_away(): void
    {
        $photo = $this->photo();

        Livewire::test('photos.page')
            ->call('hide', $photo->id)
            ->call('dismissUndo')
            ->assertSet('undoHide', null);

        $this->assertTrue($photo->fresh()->is_hidden, 'Dismissing is not undoing.');
    }

    #[Test]
    public function hiding_the_one_being_looked_at_closes_the_viewer(): void
    {
        $photo = $this->photo();

        Livewire::test('photos.page')
            ->call('openPhoto', $photo->id)
            ->assertSet('viewing', $photo->id)
            ->call('hide', $photo->id)
            ->assertSet('viewing', null);
    }

    /* ------------------------------ the viewer -------------------------- */

    #[Test]
    public function one_can_be_opened_and_stepped_through(): void
    {
        $first = $this->photo();
        $second = $this->photo();
        $third = $this->photo();

        // Newest first: each photo() is dated a day older than the last, so
        // the first made is the one at the top of the grid.
        Livewire::test('photos.page')
            ->call('openPhoto', $first->id)
            ->assertSet('viewing', $first->id)
            ->call('step', 1)
            ->assertSet('viewing', $second->id)
            ->call('step', 1)
            ->assertSet('viewing', $third->id)
            ->call('step', -1)
            ->assertSet('viewing', $second->id);
    }

    /**
     * The counter has to move with it.
     *
     * viewerIndex is memoised for the request and step() reads it before it
     * changes which photograph is showing, so this is the assertion that
     * catches the stale one.
     */
    #[Test]
    public function the_position_shown_moves_with_the_photograph(): void
    {
        $first = $this->photo();
        $this->photo();
        $this->photo();

        Livewire::test('photos.page')
            ->call('openPhoto', $first->id)
            ->assertSee('1 of 3')
            ->call('step', 1)
            ->assertSee('2 of 3')
            ->call('step', 1)
            ->assertSee('3 of 3');
    }

    #[Test]
    public function stepping_past_either_end_stays_put(): void
    {
        $only = $this->photo();

        Livewire::test('photos.page')
            ->call('openPhoto', $only->id)
            ->call('step', 1)
            ->assertSet('viewing', $only->id)
            ->call('step', -1)
            ->assertSet('viewing', $only->id);
    }

    #[Test]
    public function the_viewer_closes(): void
    {
        $photo = $this->photo();

        Livewire::test('photos.page')
            ->call('openPhoto', $photo->id)
            ->call('closeViewer')
            ->assertSet('viewing', null);
    }

    #[Test]
    public function another_households_photograph_cannot_be_opened(): void
    {
        $theirs = Photo::create([
            'household_id' => Household::factory()->create()->id,
            'source' => 'upload',
            'disk' => 'public',
            'path' => 'photos/theirs.jpg',
        ]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test('photos.page')->call('openPhoto', $theirs->id);
    }

    /* ------------------------------- the wall --------------------------- */

    #[Test]
    public function the_wall_gets_the_library_rather_than_a_placeholder(): void
    {
        $this->photo(['caption' => 'Cornwall']);

        Livewire::test('photos.page', ['onWall' => true])
            ->assertSee('Cornwall')
            ->assertDontSee('Arrives in a later phase');
    }

    /** A kiosk has no file picker worth the name. */
    #[Test]
    public function the_wall_is_not_offered_an_upload(): void
    {
        Livewire::test('photos.page', ['onWall' => true])->assertDontSee('Add photographs');

        Livewire::test('photos.page')->assertSee('Add photographs');
    }

    /* ------------------------------ empty state ------------------------- */

    /**
     * The answer to "how do I get photographs on here" is somewhere else
     * entirely, so the empty state's only job is to say where.
     */
    #[Test]
    public function the_empty_state_explains_where_photographs_come_from(): void
    {
        Livewire::test('photos.page')
            ->assertSee('No photographs yet')
            ->assertSee('iCloud shared album')
            ->assertSee('Wall display')
            ->assertSee(route('admin'), false);
    }
}
