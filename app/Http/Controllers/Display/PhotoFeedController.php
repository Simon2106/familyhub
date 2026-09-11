<?php

namespace App\Http\Controllers\Display;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Services\PhotoLibrary;
use Illuminate\Http\JsonResponse;

/**
 * The photographs the screensaver is cycling, asked for again.
 *
 * The wall bakes its photo list into the page at render, and a wall reloads
 * only on a deploy — so without this, an album added to on Sunday would not
 * reach the screen until something else happened to reload it. The wall polls
 * this while the screensaver is up, which is the only time the answer matters.
 */
class PhotoFeedController extends Controller
{
    public function __invoke(PhotoLibrary $library): JsonResponse
    {
        return response()->json([
            'photos' => $library->photos()
                ->map(fn (Photo $photo) => [
                    'url' => $photo->url(),
                    'caption' => $photo->caption,
                ])
                ->values()
                ->all(),
        ])->header('Cache-Control', 'no-store, max-age=0');
    }
}
