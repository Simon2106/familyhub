<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Notifications\Notice;
use App\Services\Notifications\Notifier;
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

    /**
     * Send one, now, to whoever asked.
     *
     * Deliberately not routed through Notifier::tell(): that checks the
     * person's settings, honours quiet hours and writes a ledger row so the
     * same thing is never sent twice — all correct for a real notice, and all
     * wrong for somebody standing there tapping "test" at half past ten at
     * night wanting to know whether any of this works.
     *
     * The reply says which of the several possible failures it was, because
     * "nothing arrived" is the one symptom they all share.
     */
    public function test(Request $request, Notifier $notifier): JsonResponse
    {
        if (! $notifier->isConfigured()) {
            return response()->json([
                'message' => 'No push keys are set on the server yet, so nothing can be sent.',
            ], 503);
        }

        $devices = $request->user()->pushSubscriptions()->count();

        if ($devices === 0) {
            return response()->json([
                'message' => 'This device is not signed up yet — turn notifications on above first.',
            ], 422);
        }

        $sent = $notifier->push($request->user(), new Notice(
            trigger: 'test',
            subject: 'test:'.now()->timestamp,
            title: 'FamilyHub',
            body: 'This is the test notification. Everything is working.',
            url: route('notifications'),
        ));

        if (! $sent) {
            return response()->json([
                'message' => 'The server tried, but no device accepted it. It may have been removed — turn notifications off and on again.',
            ], 502);
        }

        return response()->json([
            'ok' => true,
            'message' => $devices === 1
                ? 'Sent. It should arrive in a moment.'
                : 'Sent to all '.$devices.' of your devices. It should arrive in a moment.',
        ]);
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
