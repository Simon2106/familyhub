<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Services\Capture\CaptureIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Receives a PWA share.
 *
 * iOS posts whatever the sharing app offered: a title and text, a URL, files,
 * or some combination. All of it becomes one capture.
 */
class ShareTargetController
{
    public function __invoke(Request $request, CaptureIntake $intake): RedirectResponse
    {
        $request->validate([
            'title' => 'nullable|string|max:500',
            'text' => 'nullable|string|max:100000',
            'url' => 'nullable|string|max:2000',
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|max:20480|mimetypes:application/pdf,image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif',
        ]);

        $files = $request->file('files', []);
        $text = trim((string) $request->input('text'));
        $url = trim((string) $request->input('url'));

        // Some apps put the link in `text` rather than `url`.
        if ($url === '' && preg_match('#^https?://\S+$#i', $text)) {
            $url = $text;
            $text = '';
        }

        if ($files === [] && $text === '' && $url === '') {
            return redirect()->route('review')->with('capture-error', 'That share had nothing in it.');
        }

        // A bare link is worth fetching; a link alongside text is context.
        if ($files === [] && $text === '' && $url !== '') {
            try {
                $intake->fromUrl($url, Household::current());

                return redirect()->route('review');
            } catch (Throwable $e) {
                return redirect()->route('review')->with('capture-error', $e->getMessage());
            }
        }

        $intake->create('share', [
            'household' => Household::current(),
            'subject' => $request->input('title') ?: ($url ?: 'Shared to FamilyHub'),
            'body_text' => trim($text.($url !== '' ? "\n\nLink: ".$url : '')),
        ], $files);

        return redirect()->route('review');
    }
}
