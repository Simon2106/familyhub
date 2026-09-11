<?php

namespace Tests\Feature\Photos;

use App\Models\Household;
use App\Models\Photo;
use App\Models\User;
use App\Services\Photos\CloudKitSharedAlbum;
use App\Services\Photos\IcloudSharedAlbum;
use App\Services\Photos\SharedAlbumLink;
use App\Services\Photos\SharedAlbums;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading a shared album through CloudKit.
 *
 * Apple moved the sharing pages from www.icloud.com/sharedalbum/#B0… to
 * photos.icloud.com/shared/album/…, and the two are served by entirely
 * different backends. Both have to keep working: a household that copied
 * their link two years ago should not have to go and copy it again.
 */
class CloudKitSharedAlbumTest extends TestCase
{
    use RefreshDatabase;

    public const KEY = '0232XSPjoXo99QhjlZqRd_Q2g';

    public const LINK = 'https://photos.icloud.com/shared/album/'.self::KEY;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['familyhub.photos.disk' => 'public', 'familyhub.photos.path' => 'photos']);

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
    }

    /* ------------------------------ the link ----------------------------- */

    #[Test]
    public function both_shapes_of_shared_album_link_are_recognised(): void
    {
        $new = SharedAlbumLink::parse(self::LINK);

        $this->assertNotNull($new);
        $this->assertTrue($new->isCloudKit());
        $this->assertSame(self::KEY, $new->key);

        $old = SharedAlbumLink::parse('https://www.icloud.com/sharedalbum/#B0abcDEF123');

        $this->assertNotNull($old);
        $this->assertFalse($old->isCloudKit());
        $this->assertSame('B0abcDEF123', $old->key);

        // Pasted links carry whitespace and trailing slashes.
        $this->assertSame(self::KEY, SharedAlbumLink::parse('  '.self::LINK.'/  ')?->key);

        $this->assertNull(SharedAlbumLink::parse('https://photos.google.com/share/xyz'));
        $this->assertNull(SharedAlbumLink::parse(''));
        $this->assertNull(SharedAlbumLink::parse(null));
    }

    #[Test]
    public function the_link_decides_which_backend_reads_it(): void
    {
        $albums = app(SharedAlbums::class);

        $this->assertInstanceOf(CloudKitSharedAlbum::class, $albums->for(self::LINK));
        $this->assertInstanceOf(IcloudSharedAlbum::class, $albums->for('https://www.icloud.com/sharedalbum/#B0abc'));
        $this->assertNull($albums->for('https://photos.google.com/share/xyz'));
    }

    #[Test]
    public function saving_a_link_keeps_the_link_and_the_key(): void
    {
        $this->household->setPhotoAlbumUrl('  '.self::LINK.'  ');

        $household = $this->household->fresh();

        $this->assertSame(self::LINK, $household->photoAlbumUrl());
        $this->assertSame(self::KEY, $household->photoAlbumKey());
    }

    /* ------------------------------ resolving ---------------------------- */

    /** What the album currently holds, so a second call can change it. */
    protected array $albumRecords = [];

    /**
     * Stand in for the album.
     *
     * The records live in a property rather than the closure because
     * Http::fake() merges its stubs and the first match wins — so calling it
     * again to change what the album holds would quietly keep answering with
     * the old contents, and a test about photographs disappearing would pass
     * whatever the code did.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, string>  $resolveHeaders
     */
    protected function fakeAlbum(
        array $records,
        array $resolveHeaders = ['x-apple-user-partition' => '24'],
        ?array $resolveBody = null,
    ): void {
        $this->albumRecords = $records;

        Http::fake([
            '*records/resolve*' => Http::response($resolveBody ?? $this->resolveBody(), 200, $resolveHeaders),
            '*records/query*' => fn () => Http::response(['records' => $this->albumRecords]),
            'https://cvws-h2.icloud-content.com/*' => Http::response('a jpeg, honestly'),
        ]);
    }

    /** Change what the album holds, without re-stubbing anything. */
    protected function albumNowHolds(array $records): void
    {
        $this->albumRecords = $records;
    }

    /** @return array<string, mixed> */
    protected function resolveBody(?string $token = 'anon-token'): array
    {
        return ['results' => [[
            'zoneID' => [
                'zoneName' => 'SharedCollection-831FA51F',
                'ownerRecordName' => '_d7c32875a7cc4608',
                'zoneType' => 'REGULAR_CUSTOM_ZONE',
            ],
            'share' => ['fields' => ['cloudkit.title' => ['value' => 'Home Hub', 'type' => 'STRING']]],
            'anonymousPublicAccess' => $token === null ? [] : ['token' => $token],
        ]]];
    }

    /** @return array<string, mixed> */
    protected function master(string $name, string $itemType = 'public.heic'): array
    {
        return [
            'recordName' => $name,
            'recordType' => 'CPLMaster',
            'fields' => ['itemType' => ['value' => $itemType, 'type' => 'STRING']],
        ];
    }

    /**
     * A CPLAsset as the live API returns one.
     *
     * `$fullType` is the trap: on a photograph taken as HEIC, Apple's
     * "resJPEGFullRes" is a HEIC. The field name means "full size", not
     * "JPEG", and only resJPEGFullFileType says which.
     *
     * @return array<string, mixed>
     */
    protected function asset(
        string $name,
        string $master,
        int $takenAtMs = 1755277045000,
        string $fullType = 'public.jpeg',
    ): array {
        return [
            'recordName' => $name,
            'recordType' => 'CPLAsset',
            'fields' => [
                'masterRef' => ['value' => ['recordName' => $master], 'type' => 'REFERENCE'],
                'assetDate' => ['value' => $takenAtMs, 'type' => 'TIMESTAMP'],
                'resJPEGFullRes' => ['value' => [
                    'downloadURL' => 'https://cvws-h2.icloud-content.com/B/'.$name.'/full',
                ], 'type' => 'ASSETID'],
                'resJPEGFullFileType' => ['value' => $fullType, 'type' => 'STRING'],
                'resJPEGMedRes' => ['value' => [
                    'downloadURL' => 'https://cvws-h2.icloud-content.com/B/'.$name.'/med',
                ], 'type' => 'ASSETID'],
                'resJPEGMedFileType' => ['value' => 'public.jpeg', 'type' => 'STRING'],
            ],
        ];
    }

    #[Test]
    public function the_shard_comes_from_the_response_header_rather_than_a_guess(): void
    {
        // Apple hands albums out across numbered partitions, and a query sent
        // to the wrong one answers with nothing rather than an error — much the
        // worst way to find out.
        $this->fakeAlbum([$this->master('m1'), $this->asset('a1', 'm1')], ['x-apple-user-partition' => '154']);

        app(CloudKitSharedAlbum::class)->photos(self::LINK);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'records/query')
            && str_starts_with($request->url(), 'https://p154-ckdatabasews.icloud.com/'));
    }

    #[Test]
    public function the_token_is_read_from_the_header_when_apple_sends_one(): void
    {
        $this->fakeAlbum(
            [$this->master('m1'), $this->asset('a1', 'm1')],
            ['x-apple-user-partition' => '24', 'X-CloudKit-PublicAccess-AuthToken' => 'from-the-header'],
        );

        app(CloudKitSharedAlbum::class)->photos(self::LINK);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'records/query')
            || str_contains($request->url(), 'publicAccessAuthToken=from-the-header'));
    }

    #[Test]
    public function the_token_falls_back_to_the_body_when_there_is_no_header(): void
    {
        // Which is what the live API actually does today: the resolve reply
        // carries no auth-token header at all, only results.0.anonymousPublicAccess.
        $this->fakeAlbum([$this->master('m1'), $this->asset('a1', 'm1')]);

        app(CloudKitSharedAlbum::class)->photos(self::LINK);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'records/query')
            || str_contains($request->url(), 'publicAccessAuthToken=anon-token'));
    }

    #[Test]
    public function an_album_that_hands_out_no_token_says_so(): void
    {
        $this->fakeAlbum([], [], $this->resolveBody(token: null));

        $this->expectExceptionMessage('did not hand out an access token');

        app(CloudKitSharedAlbum::class)->photos(self::LINK);
    }

    #[Test]
    public function the_album_is_called_what_its_owner_called_it(): void
    {
        $this->fakeAlbum([]);

        $this->assertSame('Home Hub', app(CloudKitSharedAlbum::class)->name(self::LINK));
    }

    #[Test]
    public function the_token_is_fetched_afresh_for_every_run(): void
    {
        // Tokens are short-lived, so a run that reuses yesterday's gets a 401
        // in the middle of the night and nobody finds out until the wall is blank.
        $this->fakeAlbum([$this->master('m1'), $this->asset('a1', 'm1')]);
        $this->household->setPhotoAlbumUrl(self::LINK);

        $this->artisan('familyhub:sync-photos');
        $this->artisan('familyhub:sync-photos');

        $resolves = 0;

        Http::assertSent(function ($request) use (&$resolves) {
            $resolves += str_contains($request->url(), 'records/resolve') ? 1 : 0;

            return true;
        });

        $this->assertSame(2, $resolves, 'Each run must resolve its own token.');
    }

    /* ------------------------------ the photos --------------------------- */

    #[Test]
    public function photographs_are_read_with_their_dates_newest_first(): void
    {
        $this->fakeAlbum([
            $this->master('m1'),
            $this->asset('a1', 'm1', takenAtMs: 1_600_000_000_000),
            $this->master('m2'),
            $this->asset('a2', 'm2', takenAtMs: 1_700_000_000_000),
        ]);

        $photos = app(CloudKitSharedAlbum::class)->photos(self::LINK);

        $this->assertSame(['a2', 'a1'], array_column($photos, 'id'));
        $this->assertStringStartsWith('2023-', $photos[0]['taken_at']);
    }

    #[Test]
    public function the_biggest_rendition_that_is_actually_a_jpeg_is_the_one_fetched(): void
    {
        // Apple's "resJPEGFullRes" is a HEIC whenever the photograph was taken
        // as one — the name means full size, not JPEG — and the wall shows a
        // broken image for those. The sibling FileType field is the only thing
        // that says what the bytes are.
        $this->fakeAlbum([
            $this->master('m1'),
            $this->asset('a1', 'm1', fullType: 'public.heic'),
            $this->master('m2'),
            $this->asset('a2', 'm2', fullType: 'public.jpeg'),
        ]);

        $photos = collect(app(CloudKitSharedAlbum::class)->photos(self::LINK))->keyBy('id');

        $this->assertStringEndsWith('/a1/med', $photos['a1']['url']);
        $this->assertStringEndsWith('/a2/full', $photos['a2']['url']);
    }

    #[Test]
    public function a_rendition_with_no_file_type_at_all_is_still_taken(): void
    {
        // The older shape of the reply, where these really were all JPEGs.
        $this->fakeAlbum([['recordName' => 'a1', 'recordType' => 'CPLAsset', 'fields' => [
            'resJPEGFullRes' => ['value' => ['downloadURL' => 'https://cvws-h2.icloud-content.com/B/plain.jpg']],
        ]]]);

        $photos = app(CloudKitSharedAlbum::class)->photos(self::LINK);

        $this->assertSame('https://cvws-h2.icloud-content.com/B/plain.jpg', $photos[0]['url']);
    }

    #[Test]
    public function a_video_in_the_album_is_left_where_it_is(): void
    {
        // A wall has no business autoplaying one.
        $this->fakeAlbum([
            $this->master('m1', 'public.heic'),
            $this->asset('a1', 'm1'),
            $this->master('m2', 'com.apple.quicktime-movie'),
            $this->asset('a2', 'm2'),
        ]);

        $this->assertSame(['a1'], array_column(app(CloudKitSharedAlbum::class)->photos(self::LINK), 'id'));
    }

    #[Test]
    public function an_album_bigger_than_one_page_is_read_all_the_way_through(): void
    {
        Http::fake([
            '*records/resolve*' => Http::response($this->resolveBody(), 200, ['x-apple-user-partition' => '24']),
            '*records/query*' => Http::sequence()
                ->push(['records' => [$this->master('m1'), $this->asset('a1', 'm1')], 'continuationMarker' => 'more'])
                ->push(['records' => [$this->master('m2'), $this->asset('a2', 'm2')]]),
            'https://cvws-h2.icloud-content.com/*' => Http::response('bytes'),
        ]);

        $photos = app(CloudKitSharedAlbum::class)->photos(self::LINK);

        $this->assertCount(2, $photos);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'records/query')
            || ! str_contains((string) $request->body(), 'continuationMarker')
            || str_contains((string) $request->body(), '"more"'));
    }

    /* ------------------------------ the sync ----------------------------- */

    #[Test]
    public function the_album_is_downloaded_and_kept_under_its_cloudkit_name(): void
    {
        $this->household->setPhotoAlbumUrl(self::LINK);
        $this->fakeAlbum([$this->master('m1'), $this->asset('D7203186-321D', 'm1')]);

        $this->artisan('familyhub:sync-photos')->assertSuccessful();

        $photo = Photo::firstWhere('source', 'icloud');

        $this->assertNotNull($photo);
        $this->assertSame('D7203186-321D', $photo->external_id);
        Storage::disk('public')->assertExists($photo->path);

        $this->assertSame('Home Hub', $this->household->fresh()->photoAlbumName());
        $this->assertSame(1, $this->household->fresh()->photoAlbumCount());
        $this->assertNotNull($this->household->fresh()->photosSyncedAt());
    }

    #[Test]
    public function syncing_twice_does_not_fetch_the_same_photograph_again(): void
    {
        $this->household->setPhotoAlbumUrl(self::LINK);
        $this->fakeAlbum([$this->master('m1'), $this->asset('a1', 'm1')]);

        $this->artisan('familyhub:sync-photos');
        $this->artisan('familyhub:sync-photos');

        $this->assertSame(1, Photo::where('source', 'icloud')->count());
    }

    #[Test]
    public function a_photograph_taken_out_of_the_album_comes_off_the_wall(): void
    {
        $this->household->setPhotoAlbumUrl(self::LINK);
        $this->fakeAlbum([
            $this->master('m1'), $this->asset('a1', 'm1'),
            $this->master('m2'), $this->asset('a2', 'm2'),
        ]);

        $this->artisan('familyhub:sync-photos');

        $this->assertSame(2, Photo::where('source', 'icloud')->count());

        $gone = Photo::firstWhere('external_id', 'a2');

        // Somebody removes one on their phone; the next run notices.
        $this->albumNowHolds([$this->master('m1'), $this->asset('a1', 'm1')]);

        $this->artisan('familyhub:sync-photos')->assertSuccessful();

        $this->assertSame(['a1'], Photo::where('source', 'icloud')->pluck('external_id')->all());
        Storage::disk('public')->assertMissing($gone->path);
    }

    #[Test]
    public function photographs_put_here_by_hand_are_never_pruned(): void
    {
        // Nobody shared those with us; the album does not get to vouch for them.
        $this->household->setPhotoAlbumUrl(self::LINK);

        $upload = Photo::create([
            'household_id' => $this->household->id,
            'source' => 'upload',
            'disk' => 'public',
            'path' => 'photos/by-hand.jpg',
            'taken_at' => now(),
        ]);

        $this->fakeAlbum([$this->master('m1'), $this->asset('a1', 'm1')]);

        $this->artisan('familyhub:sync-photos');

        $this->assertNotNull($upload->fresh());
    }

    #[Test]
    public function an_album_that_answers_with_nothing_does_not_empty_the_wall(): void
    {
        // Far likelier a bad afternoon at Apple than a family deleting every
        // photograph they own.
        $this->household->setPhotoAlbumUrl(self::LINK);
        $this->fakeAlbum([$this->master('m1'), $this->asset('a1', 'm1')]);

        $this->artisan('familyhub:sync-photos');

        $this->albumNowHolds([]);

        $this->artisan('familyhub:sync-photos')->assertSuccessful();

        $this->assertSame(1, Photo::where('source', 'icloud')->count());
    }

    #[Test]
    public function a_read_cut_short_by_the_limit_prunes_nothing(): void
    {
        // Only the first page came back, so what is missing from it is not
        // evidence of anything.
        $this->household->setPhotoAlbumUrl(self::LINK);
        $this->fakeAlbum([
            $this->master('m1'), $this->asset('a1', 'm1'),
            $this->master('m2'), $this->asset('a2', 'm2'),
        ]);

        $this->artisan('familyhub:sync-photos');

        $this->albumNowHolds([$this->master('m3'), $this->asset('a3', 'm3')]);

        $this->artisan('familyhub:sync-photos', ['--limit' => 1])->assertSuccessful();

        $this->assertSame(3, Photo::where('source', 'icloud')->count());
    }

    /* ------------------------------- /admin ------------------------------ */

    /** @param array<string, mixed> $overrides */
    protected function saveSettings(array $overrides = []): Testable
    {
        return Livewire::test('admin.settings')
            ->set('householdName', 'Our Family')
            ->set('doneRetentionDays', 30)
            ->set('todoLeadDays', 7)
            ->set('screenOffStart', '23:00')
            ->set('screenOffEnd', '06:00')
            ->set('darkStart', '21:00')
            ->set('darkEnd', '06:30')
            ->set('screensaverMinutes', 10)
            ->set('screensaverStyle', 'photos')
            ->set($overrides)
            ->call('saveHousehold');
    }

    #[Test]
    public function admin_accepts_either_shape_of_link_and_refuses_anything_else(): void
    {
        $this->saveSettings(['photoAlbumUrl' => '  '.self::LINK.'  '])->assertHasNoErrors();
        $this->assertSame(self::LINK, $this->household->fresh()->photoAlbumUrl());

        $old = 'https://www.icloud.com/sharedalbum/#B0abcdefghij';
        $this->saveSettings(['photoAlbumUrl' => $old])->assertHasNoErrors();
        $this->assertSame($old, $this->household->fresh()->photoAlbumUrl());

        $this->saveSettings(['photoAlbumUrl' => 'https://photos.google.com/share/xyz'])
            ->assertHasErrors('photoAlbumUrl');

        // And the album it had is left where it was rather than overwritten
        // with the rubbish.
        $this->assertSame($old, $this->household->fresh()->photoAlbumUrl());
    }

    #[Test]
    public function admin_and_the_photos_tab_both_show_when_it_last_ran_and_how_many(): void
    {
        $this->household->setPhotoAlbumUrl(self::LINK);
        $this->fakeAlbum([$this->master('m1'), $this->asset('a1', 'm1')]);

        $this->artisan('familyhub:sync-photos');

        Once::flush();

        Livewire::test('admin.settings')
            ->set('screensaverStyle', 'photos')
            ->assertSee('1 photograph')
            ->assertSee('Last read');

        Livewire::test('photos.page')
            ->assertSee('Home Hub')
            ->assertSee('1 photograph');
    }

    #[Test]
    public function admin_shows_the_last_error_where_somebody_will_see_it(): void
    {
        $this->household->setPhotoAlbumUrl(self::LINK);

        Http::fake(['*records/resolve*' => Http::response('nope', 503)]);

        $this->artisan('familyhub:sync-photos')->assertFailed();

        Once::flush();

        Livewire::test('admin.settings')
            ->set('screensaverStyle', 'photos')
            ->assertSee('Last try failed');
    }

    #[Test]
    public function sync_now_in_admin_reads_the_album(): void
    {
        $this->household->setPhotoAlbumUrl(self::LINK);
        $this->fakeAlbum([$this->master('m1'), $this->asset('a1', 'm1')]);

        Livewire::test('admin.settings')
            ->set('screensaverStyle', 'photos')
            ->call('syncPhotosNow')
            ->assertSet('photoProblem', '');

        $this->assertSame(1, Photo::where('source', 'icloud')->count());
    }

    /* ------------------------------ failures ----------------------------- */

    #[Test]
    public function an_album_that_will_not_open_is_recorded_rather_than_only_printed(): void
    {
        $this->household->setPhotoAlbumUrl(self::LINK);

        Http::fake(['*records/resolve*' => Http::response('nope', 503)]);

        $this->artisan('familyhub:sync-photos')->assertFailed();

        $this->assertStringContainsString('503', (string) $this->household->fresh()->photoSyncError());
    }
}
