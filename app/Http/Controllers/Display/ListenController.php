<?php

namespace App\Http\Controllers\Display;

use App\Http\Controllers\Controller;
use App\Jobs\AnswerSpokenQuestionJob;
use App\Services\Speech\Contracts\SpeechToText;
use App\Services\Speech\SpokenQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The wall's microphone, server side.
 *
 * Three small endpoints rather than one long one: recording, answering and
 * speaking happen on three different clocks, and a POST that waited for all of
 * them would look to the kiosk exactly like a crash.
 *
 * All of them sit behind the display token, which is the same gate the wall
 * itself is behind.
 */
class ListenController extends Controller
{
    /** Take a recording and start working on it. */
    public function store(Request $request, SpeechToText $ears): JsonResponse
    {
        if (! $ears->isConfigured()) {
            // Said plainly rather than 500'd: an unset key is a setup step, not
            // a fault, and the wall has to be able to say which.
            return response()->json([
                'error' => 'Listening is not set up yet — no OpenAI key.',
            ], 503);
        }

        $request->validate([
            'audio' => 'required|file|max:'.(int) config('familyhub.openai.max_upload_kb'),
        ]);

        $question = SpokenQuestion::start();
        $question->storeRecording((string) file_get_contents($request->file('audio')->getRealPath()));

        AnswerSpokenQuestionJob::dispatch($question->id);

        return response()->json(['id' => $question->id]);
    }

    /** Where that question has got to. Polled by the display. */
    public function show(string $id): JsonResponse
    {
        $question = SpokenQuestion::find($id);

        if (! $question) {
            return response()->json(['status' => 'failed', 'error' => 'That question has been forgotten.'], 404);
        }

        return response()->json(['id' => $question->id] + $question->state());
    }

    /** The answer, read out. */
    public function speech(string $id): Response|StreamedResponse
    {
        $audio = SpokenQuestion::find($id)?->answerAudio();

        abort_if($audio === null, 404);

        return response($audio, 200, [
            'Content-Type' => 'audio/mpeg',
            // Nothing here is worth a cache: every one of these is heard once.
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
