<?php

namespace App\Mail;

use App\Models\NoticeSent;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * What you were not told at the time.
 *
 * The fallback for a household with no push set up, and the safety net for the
 * hours when the wall is dimmed and nobody wants a phone lighting up a bedroom.
 */
class NoticeDigest extends Mailable
{
    use Queueable;

    /** @param Collection<int, NoticeSent> $notices */
    public function __construct(
        public User $user,
        public Collection $notices,
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->notices->count();

        return new Envelope(
            subject: $count === 1
                ? $this->notices->first()->title
                : $count.' things from FamilyHub',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.notice-digest');
    }
}
