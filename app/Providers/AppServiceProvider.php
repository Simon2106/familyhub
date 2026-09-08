<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Assistant\ClaudeAssistant;
use App\Services\Assistant\Contracts\Assistant;
use App\Services\Capture\ClaudeItemExtractor;
use App\Services\Capture\Contracts\ItemExtractor;
use App\Services\Recipes\ClaudeRecipeReader;
use App\Services\Recipes\Contracts\RecipeReader;
use App\Services\Speech\Contracts\SpeechToText;
use App\Services\Speech\Contracts\TextToSpeech;
use App\Services\Speech\OpenAiSpeechToText;
use App\Services\Speech\OpenAiTextToSpeech;
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

        // Bound to interfaces so tests can swap in fakes and exercise the
        // whole capture and recipe pipelines without calling the API.
        $this->app->bind(ItemExtractor::class, ClaudeItemExtractor::class);
        $this->app->bind(RecipeReader::class, ClaudeRecipeReader::class);
        $this->app->bind(Assistant::class, ClaudeAssistant::class);

        // Both halves of the wall's microphone, behind interfaces: the
        // provider is an implementation detail with a short shelf life, and
        // tests swap in fakes rather than reaching the network.
        $this->app->bind(SpeechToText::class, OpenAiSpeechToText::class);
        $this->app->bind(TextToSpeech::class, OpenAiTextToSpeech::class);
    }

    public function boot(): void
    {
        //
    }
}
