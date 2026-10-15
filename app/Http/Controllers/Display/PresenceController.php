<?php

namespace App\Http\Controllers\Display;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Services\HomeAssistant\Presence;
use Illuminate\Http\JsonResponse;

/**
 * What the sensors say, for the wall to poll.
 *
 * Five seconds, as the brief asks, and cheap: where the websocket listener
 * is running this reads the cache it keeps warm and never touches Home
 * Assistant at all. Behind the display token like everything else.
 */
class PresenceController extends Controller
{
    public function __invoke(Presence $presence): JsonResponse
    {
        return response()
            ->json($presence->read(Household::current()))
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
