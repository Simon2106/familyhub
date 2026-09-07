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
    /** @return array<string, mixed> */
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
                            'start' => [
                                'type' => ['string', 'null'],
                                'description' => 'ISO 8601 local date-time (2026-09-15T09:00:00) or date (2026-09-15) for all-day. Null only if genuinely undated.',
                            ],
                            'end' => [
                                'type' => ['string', 'null'],
                                'description' => 'ISO 8601, same form as start. Null if unknown.',
                            ],
                            'all_day' => [
                                'type' => 'boolean',
                                'description' => 'True when no time of day was given.',
                            ],
                            'location' => ['type' => ['string', 'null']],
                            'notes' => [
                                'type' => ['string', 'null'],
                                'description' => 'Anything a parent would want, including what was ambiguous and why.',
                            ],
                            'member_hint' => [
                                'type' => ['string', 'null'],
                                'description' => 'Any name, class, year group or school mentioned that says who this is for. Verbatim.',
                            ],
                            'confidence' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'maximum' => 100,
                                'description' => 'How sure you are of BOTH the date and that this is a real commitment. Below 80 if the year was inferred, the date was relative, or the text was unclear.',
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

        What matters most:

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
        - `member_hint` should quote whatever the source said about who it concerns — a
          name, a class like "4B", a year group, a school name. Do not guess a family
          member; the household will map it themselves.

        Titles should read the way a parent would write them on a wall calendar: "Year 4
        trip to Warwick Castle", not "Letter regarding the forthcoming Year 4 educational
        visit". Never put the date in the title.
        PROMPT;
    }
}
