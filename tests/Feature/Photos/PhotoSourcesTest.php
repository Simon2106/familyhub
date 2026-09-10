<?php

namespace Tests\Feature\Photos;

use App\Models\Household;
use App\Models\Photo;
use App\Models\User;
use App\Services\PhotoLibrary;
use App\Services\Photos\IcloudSharedAlbum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Where the screensaver's photographs come from. */
class PhotoSourcesTest extends TestCase
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

    /* ------------------------------- upload ------------------------------ */

    #[Test]
    public function a_photograph_can_be_added_from_a_phone(): void
    {
        Livewire::test('photos.page')
            ->set('uploads', [UploadedFile::fake()->image('beach.jpg')])
            ->call('save')
            ->assertHasNoErrors();

        $photo = Photo::first();

        $this->assertNotNull($photo);
        $this->assertSame('upload', $photo->source);
        Storage::disk('public')->assertExists($photo->path);
    }

    #[Test]
    public function something_that_is_not_a_photograph_is_refused(): void
    {
        Livewire::test('photos.page')
            ->set('uploads', [UploadedFile::fake()->create('accounts.pdf', 20)])
            ->call('save')
            ->assertHasErrors('uploads.0');

        $this->assertSame(0, Photo::count());
    }

    /* ------------------------------ captions ----------------------------- */

    #[Test]
    public function a_caption_can_be_given_and_taken_away(): void
    {
        $photo = Photo::factory()->create(['household_id' => $this->household->id]);

        Livewire::test('photos.page')
            ->call('editCaption', $photo->id)
            ->set('caption', 'Cornwall, last summer')
            ->call('saveCaption');

        $this->assertSame('Cornwall, last summer', $photo->fresh()->caption);

        Livewire::test('photos.page')
            ->call('editCaption', $photo->id)
            ->set('caption', '   ')
            ->call('saveCaption');

        $this->assertNull($photo->fresh()->caption);
    }

    /* ------------------------------- hiding ------------------------------ */

    #[Test]
    public function a_hidden_photograph_is_kept_rather_than_deleted(): void
    {
        // So the next sync does not quietly bring it back.
        $photo = Photo::factory()->create(['household_id' => $this->household->id]);

        Livewire::test('photos.page')->call('toggleHidden', $photo->id);

        $this->assertTrue($photo->fresh()->is_hidden);
        $this->assertSame(0, app(PhotoLibrary::class)->photos(household: $this->household)->count());
    }

    #[Test]
    public function deleting_takes_the_file_with_it(): void
    {
        Storage::disk('public')->put('photos/gone.jpg', 'not really a jpeg');

        $photo = Photo::factory()->create([
            'household_id' => $this->household->id, 'disk' => 'public', 'path' => 'photos/gone.jpg',
        ]);

        Livewire::test('photos.page')->call('forget', $photo->id);

        $this->assertModelMissing($photo);
        Storage::disk('public')->assertMissing('photos/gone.jpg');
    }

    /* ------------------------------ the folder --------------------------- */

    #[Test]
    public function photographs_already_in_the_folder_are_adopted(): void
    {
        // The folder was the whole feature before the table existed; nobody
        // should have to re-upload.
        Storage::disk('public')->put('photos/old.jpg', 'not really a jpeg');
        Storage::disk('public')->put('photos/notes.txt', 'not a photograph');

        $adopted = app(PhotoLibrary::class)->adoptLooseFiles($this->household);

        $this->assertSame(1, $adopted);
        $this->assertSame('folder', Photo::first()->source);
    }

    #[Test]
    public function adopting_twice_does_not_add_it_twice(): void
    {
        Storage::disk('public')->put('photos/old.jpg', 'not really a jpeg');

        app(PhotoLibrary::class)->adoptLooseFiles($this->household);
        app(PhotoLibrary::class)->adoptLooseFiles($this->household);

        $this->assertSame(1, Photo::count());
    }

    /* ------------------------------- icloud ------------------------------ */

    #[Test]
    public function a_shared_album_link_is_recognised_however_it_was_copied(): void
    {
        $album = app(IcloudSharedAlbum::class);

        $this->assertSame('B0abcDEF123', $album->token('https://www.icloud.com/sharedalbum/#B0abcDEF123'));
        $this->assertSame('B0abcDEF123', $album->token('  https://www.icloud.com/sharedalbum/#B0abcDEF123  '));
        $this->assertSame('B0abcDEF123', $album->token('B0abcDEF123'));
        $this->assertNull($album->token('https://photos.google.com/share/xyz'));
    }

    #[Test]
    public function an_album_is_read_and_its_photographs_downloaded(): void
    {
        // Downloaded rather than linked: iCloud's asset URLs expire in about
        // an hour, and a wall left idle all afternoon would show broken images.
        $this->household->setPhotoAlbumUrl('https://www.icloud.com/sharedalbum/#B0abc');

        Http::fake([
            '*sharedstreams/webstream' => Http::response([
                'photos' => [[
                    'photoGuid' => 'guid-1',
                    'caption' => 'Cornwall',
                    'dateCreated' => '2026-08-01T10:00:00Z',
                    'derivatives' => [
                        ['checksum' => 'small', 'fileSize' => 1000],
                        ['checksum' => 'big', 'fileSize' => 900000],
                    ],
                ]],
            ]),
            '*sharedstreams/webasseturls' => Http::response([
                'items' => ['big' => ['url_location' => 'cvws.icloud.com', 'url_path' => '/x/big.jpg']],
            ]),
            'https://cvws.icloud.com/*' => Http::response('a jpeg, honestly'),
        ]);

        $this->artisan('familyhub:sync-photos')->assertSuccessful();

        $photo = Photo::firstWhere('source', 'icloud');

        $this->assertNotNull($photo);
        $this->assertSame('Cornwall', $photo->caption);
        $this->assertSame('guid-1', $photo->external_id);
        Storage::disk('public')->assertExists($photo->path);
    }

    #[Test]
    public function the_biggest_version_is_the_one_fetched(): void
    {
        // A wall is 1920 across; the thumbnail Apple lists first is 342.
        $this->household->setPhotoAlbumUrl('https://www.icloud.com/sharedalbum/#B0abc');

        Http::fake([
            '*webstream' => Http::response(['photos' => [[
                'photoGuid' => 'guid-1',
                'derivatives' => [
                    ['checksum' => 'thumb', 'fileSize' => 100],
                    ['checksum' => 'full', 'fileSize' => 999999],
                ],
            ]]]),
            '*webasseturls' => Http::response(['items' => [
                'full' => ['url_location' => 'cvws.icloud.com', 'url_path' => '/full.jpg'],
            ]]),
            'https://cvws.icloud.com/*' => Http::response('bytes'),
        ]);

        $this->artisan('familyhub:sync-photos');

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'thumb'));
        $this->assertSame(1, Photo::where('source', 'icloud')->count());
    }

    #[Test]
    public function syncing_twice_does_not_fetch_the_same_photograph_again(): void
    {
        $this->household->setPhotoAlbumUrl('https://www.icloud.com/sharedalbum/#B0abc');

        Http::fake([
            '*webstream' => Http::response(['photos' => [[
                'photoGuid' => 'guid-1',
                'derivatives' => [['checksum' => 'full', 'fileSize' => 999]],
            ]]]),
            '*webasseturls' => Http::response(['items' => [
                'full' => ['url_location' => 'cvws.icloud.com', 'url_path' => '/full.jpg'],
            ]]),
            'https://cvws.icloud.com/*' => Http::response('bytes'),
        ]);

        $this->artisan('familyhub:sync-photos');
        $this->artisan('familyhub:sync-photos');

        $this->assertSame(1, Photo::where('source', 'icloud')->count());
    }

    #[Test]
    public function a_link_that_is_not_an_album_says_so_rather_than_failing_silently(): void
    {
        $this->household->setPhotoAlbumUrl('https://photos.google.com/share/xyz');

        $this->artisan('familyhub:sync-photos')->assertFailed();
    }

    #[Test]
    public function with_no_album_set_the_sync_still_adopts_the_folder(): void
    {
        Storage::disk('public')->put('photos/old.jpg', 'not really a jpeg');

        $this->artisan('familyhub:sync-photos')->assertSuccessful();

        $this->assertSame(1, Photo::count());
    }

    /* ------------------------------ showing ------------------------------ */

    #[Test]
    public function recent_photographs_are_favoured_without_retiring_the_rest(): void
    {
        // A wall that only ever showed this month would quietly retire every
        // photograph the family has.
        foreach (range(1, 20) as $i) {
            Photo::factory()->create([
                'household_id' => $this->household->id,
                'taken_at' => now()->subYears(3),
            ]);
        }

        foreach (range(1, 20) as $i) {
            Photo::factory()->create([
                'household_id' => $this->household->id,
                'taken_at' => now()->subDays(3),
            ]);
        }

        $shown = app(PhotoLibrary::class)->photos(10, $this->household);

        $this->assertCount(10, $shown);

        $recent = $shown->filter(fn (Photo $p) => $p->taken_at->isAfter(now()->subMonths(6)))->count();

        $this->assertGreaterThan(3, $recent, 'Recent ones are favoured…');
        $this->assertLessThan(10, $recent, '…without being the only ones.');
    }

    #[Test]
    public function the_wall_gets_the_caption_along_with_the_url(): void
    {
        Photo::factory()->create([
            'household_id' => $this->household->id, 'caption' => 'Cornwall',
        ]);

        Livewire::test('display.wall')->assertSee('Cornwall');
    }
}
