<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A browser signing itself up to be told things.
 *
 * One row per device rather than per person: the same parent has a phone and
 * an iPad, and revoking one must not silence the other.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => 'required|string|max:2000',
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
            'label' => 'nullable|string|max:60',
        ]);

        // Keyed on the endpoint's hash: subscribing twice from one browser
        // replaces the row rather than adding a second that will never be
        // used but will always be tried.
        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hash($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'device_label' => $data['label'] ?? null,
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint');

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hash($endpoint))
            ->delete();

        return response()->json(['ok' => true]);
    }
}
