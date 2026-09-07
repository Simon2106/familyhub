<?php

namespace App\Services\Capture;

use App\Models\CaptureItem;

/**
 * The JSON schema the model must answer in, and the prompt that goes with it.
 *
 * Structured outputs (output_config.format) rather than tool use: there is one
 * shape we want back and no tools to call, so constraining the response is
 * simpler and cannot half-succeed.
 */
class ExtractionSchema
{
    /**
     * @return array<string, mixed>
     *
     * Structured outputs accept a subset of JSON Schema. Notably: no `minimum`
     * or `maximum`, and nullability must be expressed with `anyOf` rather than
     * a `["string", "null"]` union — both are 400s, not warnings. Ranges are
     * stated in the description and enforced by ExtractionParser instead.
     * SchemaSupportTest guards this.
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'description' => 'Every dated or actionable thing found. Empty if there is nothing.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => CaptureItem::TYPES,
                                'description' => 'event for something at a time or on a day, task for something to do, note for context worth keeping.',
                            ],
                            'title' => [
                                'type' => 'string',
                                'description' => 'Short, in the household\'s words. No trailing dates — those go in start.',
                            ],
                            'start' => self::nullableString(
                                'ISO 8601 local date-time (2026-09-15T09:00:00) or date (2026-09-15) for all-day. Null only if genuinely undated.'
                            ),
                            'end' => self::nullableString('ISO 8601, same form as start. Null if unknown.'),
                            'all_day' => [
                                'type' => 'boolean',
                                'description' => 'True when no time of day was given.',
                            ],
                            'location' => self::nullableString('Where it happens, if stated.'),
                            'notes' => self::nullableString(
                                'Anything a parent would want, including what was ambiguous and why.'
                            ),
                            'member_hint' => self::nullableString(
                                'Any name, class, year group or school mentioned that says who this is for. Verbatim.'
                            ),
                            'confidence' => [
                                'type' => 'integer',
                                'description' => 'A whole number from 0 to 100. How sure you are of BOTH the date and that this is a real commitment. Below 80 if the year was inferred, the date was relative, or the text was unclear.',
                            ],
                        ],
                        'required' => ['type', 'title', 'start', 'end', 'all_day', 'location', 'notes', 'member_hint', 'confidence'],
                        'additionalProperties' => false,
                    ],
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'One or two sentences on what this was, for the review screen.',
                ],
            ],
            'required' => ['items', 'summary'],
            'additionalProperties' => false,
        ];
    }

    /**
     * A string that may be null.
     *
     * Union types (`["string", "null"]`) are rejected; anyOf is the supported
     * way to say this.
     *
     * @return array<string, mixed>
     */
    protected static function nullableString(string $description): array
    {
        return [
            'anyOf' => [['type' => 'string'], ['type' => 'null']],
            'description' => $description,
        ];
    }

    /**
     * The system prompt.
     *
     * Written to be stable so it caches: everything that varies per capture
     * (today's date, the household's members) goes in the user turn.
     */
    public static function systemPrompt(): string
    {
        return <<<'PROMPT'
        You read things a family has been sent — school newsletters, term calendars, club
        emails, letters photographed on a kitchen table — and pull out everything that
        belongs on their calendar or to-do list.

        ## Where the information is

        When a document is attached, THAT is the source. A forwarded email is usually just
        a covering note: "FW: Flu vaccination — see attached". The dates, deadlines and
        details are in the attachment, and the covering note may contain nothing at all.
        Read the attachment properly before concluding anything is missing, and never
        report a date as unavailable when it is printed in the document in front of you.

        ## What matters most

        - Find EVERY date. A term calendar may hold thirty. A newsletter may bury one in
          the last paragraph. Missing one is the worst outcome; a duplicate is cheap to
          reject. Work through the whole document before answering.
        - Infer the year from context when it is not written. Use the sent date, the term
          being described, and the ordering of other dates. A September newsletter listing
          "Friday 12th" means the coming September. Lower your confidence when you infer.
        - Resolve relative dates ("next Tuesday", "the week after half term") against the
          date the material was sent, which is given to you.
        - Say what is uncertain in `notes`, and let `confidence` show it. An item you had
          to guess at is more useful flagged than omitted.
        - Split a range into separate items when they are separate commitments (three
          parents evenings on three days), but keep one multi-day event as one item with a
          start and an end (a residential trip).
        - Ignore marketing, newsletters' general chat, and anything with no date and no
          action. Do not invent an item to be helpful.

        ## Events and the deadlines attached to them

        A letter often gives an event AND something the family must do before it. Produce
        BOTH, as separate items:

          "Flu vaccination at Holy Trinity School on 25th September 2026. Return the
           consent form no later than one full school day before the vaccination date."

          -> event: "Flu vaccination", start 2026-09-25
          -> task:  "Return the flu consent form", start 2026-09-24, notes explaining
                    "One full school day before the vaccination on 25 September."

        Compute the deadline date yourself and put it in `start`. Treat "school day" and
        "working day" as a weekday: counting back from a Monday lands on the Friday
        before. Always record the rule you applied in `notes`, so a parent can check it.

        A deadline you had to compute is exactly the kind of thing to be less confident
        about. Say so.

        ## Dates on tasks

        Undated tasks are allowed but should be rare. If ANY deadline can be inferred —
        stated outright, relative to an event, "by the end of term", "before the trip" —
        give the task that date. Only leave `start` null when the document genuinely gives
        nothing to work from.

        ## Who it is for

        `member_hint` should quote what the source actually said — a name, a class like
        "4B", a year group, a school or club name. You are given the household's members,
        the schools and workplaces they are associated with, and the names those go by, so
        use them to pick the right wording; do not invent an association the source does
        not support.

        One rule that matters: an action a PARENT must take — returning a form, paying,
        booking, signing, replying — concerns the parent, even when the child's school or
        the child's name appears in the letter. The vaccination is the child's; the
        consent form is the parent's. The related event and its deadline task will often
        have different member hints for that reason.

        ## Links

        If the document gives a URL for doing the thing — a consent form, a booking page,
        a payment link — put it in `notes` verbatim, on its own line. Somebody will be
        acting on this from a phone, and retyping a URL from a letter is miserable.

        ## Titles

        Titles should read the way a parent would write them on a wall calendar: "Year 4
        trip to Warwick Castle", not "Letter regarding the forthcoming Year 4 educational
        visit". Never put the date in the title.
        PROMPT;
    }
}
