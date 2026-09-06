<?php

namespace App\Support;

/**
 * Parses the household seed definitions out of .env.
 *
 * Users:   "Name|email|password"            separated by ";"
 * Members: "Name|#hexcolour|adult\child|pin" separated by ";"
 *
 * Blank segments are skipped so a trailing ";" is harmless.
 */
class EnvSeedSpec
{
    /** @return list<array{name: string, email: string, password: string}> */
    public static function users(?string $raw = null): array
    {
        $out = [];

        foreach (self::segments($raw ?? (string) config('familyhub.seed.users')) as $segment) {
            [$name, $email, $password] = array_pad(self::fields($segment), 3, null);

            if (! $name || ! $email || ! $password) {
                continue;
            }

            $out[] = ['name' => $name, 'email' => $email, 'password' => $password];
        }

        return $out;
    }

    /** @return list<array{name: string, colour: string, is_child: bool, pin: string|null}> */
    public static function members(?string $raw = null): array
    {
        $out = [];

        foreach (self::segments($raw ?? (string) config('familyhub.seed.members')) as $segment) {
            [$name, $colour, $kind, $pin] = array_pad(self::fields($segment), 4, null);

            if (! $name) {
                continue;
            }

            $isChild = strtolower((string) $kind) === 'child';

            $out[] = [
                'name' => $name,
                'colour' => self::normaliseColour($colour),
                'is_child' => $isChild,
                // A pin is meaningless for an adult, and an empty string must not
                // become a hashable value.
                'pin' => $isChild && $pin !== null && $pin !== '' ? $pin : null,
            ];
        }

        return $out;
    }

    /** @return list<string> */
    protected static function segments(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $raw)), fn ($s) => $s !== ''));
    }

    /** @return list<string> */
    protected static function fields(string $segment): array
    {
        return array_map('trim', explode('|', $segment));
    }

    protected static function normaliseColour(?string $colour): string
    {
        $colour = ltrim((string) $colour, '#');

        return preg_match('/^[0-9a-fA-F]{6}$/', $colour) ? '#'.strtolower($colour) : '#2563eb';
    }
}
