<?php

namespace App\Exceptions;

use RuntimeException;

class CalDavException extends RuntimeException
{
    public static function authenticationFailed(): self
    {
        return new self(
            'iCloud rejected those credentials. Check the Apple ID, and make sure the '
            .'password is an app-specific password rather than the account password.'
        );
    }

    public static function unexpectedStatus(string $method, string $url, int $status, string $body = ''): self
    {
        return new self(sprintf(
            '%s %s returned %d.%s',
            $method,
            $url,
            $status,
            $body !== '' ? ' '.mb_substr(strip_tags($body), 0, 200) : '',
        ));
    }

    public static function discoveryFailed(string $what): self
    {
        return new self("Could not discover the {$what} for this iCloud account.");
    }

    public static function conflict(): self
    {
        return new self('That event changed on iCloud while you were editing it. Reload and try again.');
    }
}
