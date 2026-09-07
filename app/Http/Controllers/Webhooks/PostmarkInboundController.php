<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\Household;
use App\Services\Capture\CaptureIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Postmark inbound email.
 *
 * Answers 200 for anything it accepts or deliberately ignores: Postmark retries
 * on a non-2xx, and a bounced newsletter would be retried forever.
 */
class PostmarkInboundController
{
    /**
     * Postmark accepts up to 35MB of email. Anything past what the model can
     * actually be sent is still stored — the review inbox reports it as
     * unread rather than pretending the email was empty.
     *
     * Requires nginx client_max_body_size and PHP post_max_size to be raised;
     * see the deployment section of the README.
     */
    public const MAX_ATTACHMENT_BYTES = 35_000_000;

    public const READABLE_TYPES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
    ];

    public function __invoke(Request $request, CaptureIntake $intake): JsonResponse
    {
        $payload = $request->all();

        $body = $this->body($payload);
        $files = $this->attachments($payload);

        if ($body === '' && $files === []) {
            Log::info('Ignoring empty inbound email', ['from' => $payload['From'] ?? null]);

            return response()->json(['status' => 'ignored']);
        }

        $capture = $intake->create('email', [
            'household' => Household::current(),
            'subject' => $payload['Subject'] ?? null,
            'sender' => $payload['FromFull']['Email'] ?? $payload['From'] ?? null,
            'body_text' => $body,
            'raw_payload' => $payload,
        ], $files);

        return response()->json(['status' => 'accepted', 'capture' => $capture->id]);
    }

    /** @param array<string, mixed> $payload */
    protected function body(array $payload): string
    {
        $text = trim((string) ($payload['TextBody'] ?? ''));

        if ($text !== '') {
            return $text;
        }

        // Only fall back to the HTML part when there is no text alternative;
        // stripping tags loses structure a term calendar may rely on.
        $html = (string) ($payload['HtmlBody'] ?? '');

        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\s*/?>#i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{name: string, mime: string, contents: string}>
     */
    protected function attachments(array $payload): array
    {
        $files = [];

        foreach ($payload['Attachments'] ?? [] as $attachment) {
            $mime = strtolower((string) ($attachment['ContentType'] ?? ''));
            // Postmark sends the type with parameters on some senders.
            $mime = trim(explode(';', $mime)[0]);

            if (! in_array($mime, self::READABLE_TYPES, strict: true)) {
                continue;
            }

            $contents = base64_decode((string) ($attachment['Content'] ?? ''), strict: true);

            if ($contents === false || $contents === '' || strlen($contents) > self::MAX_ATTACHMENT_BYTES) {
                continue;
            }

            $files[] = [
                'name' => (string) ($attachment['Name'] ?? 'attachment'),
                'mime' => $mime,
                'contents' => $contents,
            ];
        }

        return $files;
    }
}
