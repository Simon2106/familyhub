<?php

namespace Tests\Feature\Home;

use App\Models\Household;
use App\Models\User;
use App\Services\Weather\Forecast;
use App\Services\Weather\OpenMeteo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The weather tile: no key, cached hard, and never the reason a wall breaks. */
class WeatherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'familyhub.weather.latitude' => 51.75,
            'familyhub.weather.longitude' => -0.75,
            'familyhub.weather.cache_minutes' => 20,
        ]);

        app(OpenMeteo::class)->forget();
    }

    protected function answer(array $overrides = []): void
    {
        Http::fake([
            'api.open-meteo.com/*' => Http::response(array_replace_recursive([
                'current' => ['temperature_2m' => 14.2, 'weather_code' => 61, 'is_day' => 1],
                'daily' => [
                    'temperature_2m_max' => [17.4],
                    'temperature_2m_min' => [9.1],
                    'precipitation_probability_max' => [70],
                ],
            ], $overrides)),
        ]);
    }

    #[Test]
    public function it_asks_open_meteo_for_the_configured_place_and_needs_no_key(): void
    {
        $this->answer();

        app(OpenMeteo::class)->current();

        Http::assertSent(function ($request) {
            $this->assertStringNotContainsString('key', mb_strtolower($request->url()));

            return str_starts_with($request->url(), OpenMeteo::ENDPOINT)
                && $request['latitude'] === 51.75
                && $request['longitude'] === -0.75;
        });
    }

    #[Test]
    public function it_reads_now_and_the_rest_of_today(): void
    {
        $this->answer();

        $forecast = app(OpenMeteo::class)->current();

        $this->assertSame(14.2, $forecast->temperature);
        $this->assertSame('Rain', $forecast->description());
        $this->assertSame('17', $forecast->round($forecast->high));
        $this->assertSame('9', $forecast->round($forecast->low));
        $this->assertTrue($forecast->mentionsRain());
    }

    #[Test]
    public function rain_is_mentioned_only_when_it_is_actually_likely(): void
    {
        $this->answer(['daily' => ['precipitation_probability_max' => [10]]]);

        $this->assertFalse(app(OpenMeteo::class)->current()->mentionsRain());
    }

    #[Test]
    public function the_night_gets_a_moon_rather_than_a_sun(): void
    {
        $clear = fn (int $isDay) => new Forecast(temperature: 10, code: 0, isDay: (bool) $isDay);

        // A drawing's name, not a glyph: the kiosk has no emoji font.
        $this->assertSame('sun', $clear(1)->icon());
        $this->assertSame('moon', $clear(0)->icon());
    }

    #[Test]
    public function weather_codes_become_words_a_household_uses(): void
    {
        $cases = [0 => 'Clear', 2 => 'Mostly sunny', 3 => 'Cloudy', 45 => 'Foggy',
            55 => 'Drizzle', 63 => 'Rain', 73 => 'Snow', 81 => 'Showers', 95 => 'Thunderstorms'];

        foreach ($cases as $code => $expected) {
            $this->assertSame($expected, (new Forecast(temperature: 10, code: $code))->description(), "code {$code}");
        }
    }

    #[Test]
    public function it_is_not_fetched_again_for_every_render(): void
    {
        // A wall that polls a free service every thirty seconds is rude to it.
        $this->answer();

        app(OpenMeteo::class)->current();
        app(OpenMeteo::class)->current();
        app(OpenMeteo::class)->current();

        Http::assertSentCount(1);
    }

    #[Test]
    public function an_unreachable_service_costs_a_tile_and_nothing_else(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response('', 503)]);

        $this->assertNull(app(OpenMeteo::class)->current());
    }

    #[Test]
    public function a_nonsense_answer_is_treated_as_no_weather(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response(['unexpected' => true])]);

        $this->assertNull(app(OpenMeteo::class)->current());
    }

    #[Test]
    public function no_location_means_no_tile_and_no_request(): void
    {
        config(['familyhub.weather.latitude' => null, 'familyhub.weather.longitude' => null]);
        Http::fake();

        $this->assertNull(app(OpenMeteo::class)->current());
        Http::assertNothingSent();
    }

    #[Test]
    public function the_wall_shows_it(): void
    {
        $this->answer();

        Household::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test('display.wall')
            ->assertSee('14°')
            ->assertSee('Rain');
    }

    #[Test]
    public function the_wall_is_fine_without_it(): void
    {
        config(['familyhub.weather.latitude' => null]);

        Household::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test('display.wall')->assertOk();
    }
}
