<?php

namespace App\Mail;

use App\Models\Household;
use App\Models\NoticeSent;
use App\Models\User;
use App\Services\Summary\SummaryWeek;
use App\Services\Summary\WeeklySummary;
use Carbon\CarbonImmutable;
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
        return new Content(markdown: 'mail.notice-digest', with: ['week' => $this->week()]);
    }

    /**
     * The week itself, when a Sunday summary is one of the things being carried.
     *
     * A digest line reading "12 jobs done · 40 stars earned" is a headline
     * with no story under it. If this is the message carrying the week, it
     * should carry the week.
     */
    public function week(): ?SummaryWeek
    {
        $notice = $this->notices->firstWhere('trigger', 'weekly_summary');

        if (! $notice) {
            return null;
        }

        $household = $this->user->household ?? Household::current();

        // The week the notice was about, not the week it is being read in:
        // this digest goes out on Monday morning, by which time "this week"
        // is a different one.
        $subject = str($notice->subject)->after('week:')->toString();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $subject)) {
            return null;
        }

        return app(WeeklySummary::class)->for(
            $household,
            CarbonImmutable::createFromFormat('Y-m-d', $subject, $household->displayTimezone())->startOfDay(),
        );
    }
}
