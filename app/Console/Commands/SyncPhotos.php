<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\Photo;
use App\Services\PhotoLibrary;
use App\Services\Photos\IcloudSharedAlbum;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetch the shared album, and adopt anything dropped in the folder.
 *
 * Downloads rather than links. iCloud's asset URLs expire in about an hour, so
 * a wall left idle all afternoon would start showing broken images — and a
 * household whose internet is down would show none at all.
 */
class SyncPhotos extends Command
{
    protected $signature = 'familyhub:sync-photos {--limit=200}';

    protected $description = 'Pull the iCloud shared album and register any loose photographs';

    public function handle(IcloudSharedAlbum $album, PhotoLibrary $library): int
    {
        $household = Household::current();

        $adopted = $library->adoptLooseFiles($household);

        if ($adopted > 0) {
            $this->components->info("Adopted {$adopted} from the photos folder.");
        }

        $url = $household->photoAlbumUrl();

        if (blank($url)) {
            return self::SUCCESS;
        }

        try {
            $photos = $album->photos($url, (int) $this->option('limit'));
        } catch (Throwable $e) {
            // Recorded rather than only printed: the photos page shows when
            // the album was last read, and "it has not worked since Tuesday"
            // is the thing worth being able to see there.
            $household->recordPhotoSync(error: $e->getMessage());

            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $fetched = 0;

        foreach ($photos as $photo) {
            if ($this->alreadyHave($household, $photo['id'])) {
                continue;
            }

            if ($this->fetch($household, $photo)) {
                $fetched++;
            }
        }

        // The name is asked for once a sync rather than once a photograph;
        // a failure to get it is not a failure to sync.
        $name = null;

        try {
            $name = $album->name($url);
        } catch (Throwable) {
            // An album with no title, or Apple in a mood. Neither matters.
        }

        $household->recordPhotoSync($name);

        $this->components->info($fetched === 0
            ? 'Nothing new in the album.'
            : "Fetched {$fetched} from the album.");

        return self::SUCCESS;
    }

    protected function alreadyHave(Household $household, string $id): bool
    {
        return Photo::where('household_id', $household->id)
            ->where('external_id', $id)
            ->exists();
    }

    /** @param array{id: string, url: string, caption: ?string, taken_at: ?string} $photo */
    protected function fetch(Household $household, array $photo): bool
    {
        $diskName = (string) config('familyhub.photos.disk');
        $path = trim((string) config('familyhub.photos.path'), '/')
            .'/icloud-'.Str::slug($photo['id']).'.jpg';

        try {
            $response = Http::timeout(60)->get($photo['url']);

            if ($response->failed()) {
                return false;
            }

            Storage::disk($diskName)->put($path, $response->body());
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        Photo::updateOrCreate(
            ['household_id' => $household->id, 'disk' => $diskName, 'path' => $path],
            [
                'source' => 'icloud',
                'external_id' => $photo['id'],
                'caption' => $photo['caption'],
                'taken_at' => $photo['taken_at'] ? CarbonImmutable::parse($photo['taken_at']) : null,
            ],
        );

        return true;
    }
}
