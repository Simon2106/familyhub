<?php

namespace App\Services\Activity;

use App\Models\ActivityEvent;

/**
 * Turning a state change into something a person would say.
 *
 * "binary_sensor.back_door went from off to on" is a log line. "Back door
 * opened" is a sentence. The difference is the whole feature: a strip on a
 * kitchen wall is read in passing or not at all.
 *
 * Templated from the category rather than from the entity, because a
 * household picks the category in /admin and that is the only thing that
 * reliably says what a state means — "on" is opened for a door, lit for a
 * lamp, and nothing at all for a thermostat.
 */
class ActivityPhrase
{
    /** States that mean somebody is in. */
    public const HOME = ['home'];

    public function for(ActivityEvent $event): string
    {
        return match ($event->category) {
            'people' => $this->person($event),
            'doors' => $this->door($event),
            'heating' => $this->heating($event),
            'lights' => $this->light($event),
            default => $this->anything($event),
        };
    }

    protected function person(ActivityEvent $event): string
    {
        $to = mb_strtolower($event->to_state);

        if (in_array($to, self::HOME, true)) {
            return "{$event->label} home";
        }

        if ($to === 'not_home' || $to === '') {
            return "{$event->label} left";
        }

        // A named zone: "Ellie at school" reads better than "Ellie left".
        return "{$event->label} at {$to}";
    }

    protected function door(ActivityEvent $event): string
    {
        return match (mb_strtolower($event->to_state)) {
            'on', 'open', 'opened' => "{$event->label} opened",
            'off', 'closed' => "{$event->label} closed",
            default => "{$event->label} {$event->to_state}",
        };
    }

    protected function heating(ActivityEvent $event): string
    {
        $to = mb_strtolower($event->to_state);

        // A number is a target temperature; a word is a mode.
        if (is_numeric($to)) {
            return "{$event->label} to ".rtrim(rtrim(number_format((float) $to, 1, '.', ''), '0'), '.').'°';
        }

        return match ($to) {
            'off' => "{$event->label} off",
            'heat' => "{$event->label} on",
            default => "{$event->label} {$to}",
        };
    }

    protected function light(ActivityEvent $event): string
    {
        return match (mb_strtolower($event->to_state)) {
            'on' => "{$event->label} on",
            'off' => "{$event->label} off",
            default => "{$event->label} {$event->to_state}",
        };
    }

    protected function anything(ActivityEvent $event): string
    {
        $to = mb_strtolower($event->to_state);

        return match ($to) {
            'on' => "{$event->label} on",
            'off' => "{$event->label} off",
            'open' => "{$event->label} opened",
            'closed' => "{$event->label} closed",
            default => "{$event->label} {$event->to_state}",
        };
    }
}
