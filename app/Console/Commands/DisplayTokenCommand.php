<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DisplayTokenCommand extends Command
{
    protected $signature = 'familyhub:display-token {--new : Generate a fresh token instead of showing the current one}';

    protected $description = 'Show (or generate) the wall display token and the URL to open on the iPad';

    public function handle(): int
    {
        $token = config('familyhub.display.token');

        if ($this->option('new') || ! $token) {
            $token = bin2hex(random_bytes(24));

            $this->components->warn('Put this in your .env, then run `php artisan config:clear`:');
            $this->line('');
            $this->line('    FAMILYHUB_DISPLAY_TOKEN='.$token);
            $this->line('');

            if ($this->option('new')) {
                $this->components->info('Any iPad already paired with the old token will need re-pairing.');
            }
        }

        $url = rtrim((string) config('app.url'), '/').'/display?token='.$token;

        $this->components->twoColumnDetail('Display token', Str::mask($token, '*', 6));
        $this->components->twoColumnDetail('Open once on the iPad', $url);

        return self::SUCCESS;
    }
}
