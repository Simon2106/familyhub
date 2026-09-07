<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Capture\ClaudeItemExtractor;
use App\Services\Capture\Contracts\ItemExtractor;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, function () {
            $key = config('familyhub.anthropic.key');

            if (blank($key)) {
                throw new RuntimeException(
                    'No ANTHROPIC_API_KEY is set, so captures cannot be read. Add one to .env.'
                );
            }

            return new Client(apiKey: $key);
        });

        // Bound to the interface so tests can swap in a fake and exercise the
        // whole capture pipeline without calling the API.
        $this->app->bind(ItemExtractor::class, ClaudeItemExtractor::class);
    }

    public function boot(): void
    {
        //
    }
}
