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

        if (! $this->addressedToUs($payload)) {
            Log::info('Ignoring inbound email addressed elsewhere', [
                'from' => $payload['From'] ?? null,
                'subject' => $payload['Subject'] ?? null,
                'recipients' => $this->recipients($payload),
                'expected' => config('familyhub.inbound_address'),
            ]);

            // 200 so Postmark treats it as delivered; a non-2xx would have it
            // retrying a message that is never going to be wanted.
            return response()->json(['status' => 'ignored', 'reason' => 'recipient']);
        }

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

    /**
     * Was this actually sent to the household's capture address?
     *
     * @param  array<string, mixed>  $payload
     */
    protected function addressedToUs(array $payload): bool
    {
        $expected = $this->normalise((string) config('familyhub.inbound_address'));

        // Unconfigured means no filtering, which is how this behaved before
        // the address existed.
        if ($expected === '') {
            return true;
        }

        return in_array($expected, $this->recipients($payload), strict: true);
    }

    /**
     * Every address this message was delivered to, normalised.
     *
     * OriginalRecipient matters as much as the headers: a message auto-forwarded
     * by a rule still carries the school's address in To, and only the envelope
     * recipient says where it actually landed.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function recipients(array $payload): array
    {
        $found = [];

        foreach (['ToFull', 'CcFull', 'BccFull'] as $key) {
            foreach ($payload[$key] ?? [] as $entry) {
                if (is_array($entry) && isset($entry['Email'])) {
                    $found[] = $entry['Email'];
                }
            }
        }

        foreach (['OriginalRecipient', 'To', 'Cc', 'Bcc'] as $key) {
            foreach (explode(',', (string) ($payload[$key] ?? '')) as $address) {
                // Headers arrive as "Name <a@b.com>, other@b.com".
                if (preg_match('/<([^>]+)>/', $address, $m)) {
                    $address = $m[1];
                }

                $found[] = $address;
            }
        }

        return array_values(array_filter(array_unique(array_map($this->normalise(...), $found))));
    }

    /**
     * Lower-cased, with any +tag removed, so ai+sandygate@… reaches ai@… and
     * the household can tag a source without adding an address.
     */
    protected function normalise(string $address): string
    {
        $address = strtolower(trim($address));

        if (! str_contains($address, '@')) {
            return '';
        }

        [$local, $domain] = explode('@', $address, 2);

        return explode('+', $local, 2)[0].'@'.$domain;
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
