<?php

namespace Tests\Feature\Home;

use PHPUnit\Framework\Attributes\Test;
use Pusher\Pusher;
use ReflectionClass;
use Tests\TestCase;

/**
 * Where PHP reaches Reverb, as opposed to where a browser does.
 *
 * Two different journeys to the same process, and they were sharing one
 * address. A browser goes the long way round through nginx, which proxies
 * /reverb; PHP is already on the box. Sent to the public host, the server-side
 * client posted to /apps/{id}/events — a path nginx does not proxy — so it hit
 * Laravel, got a 404 page, and reported "Pusher error: <!DOCTYPE html>".
 */
class ReverbReachTest extends TestCase
{
    protected function optionsWith(array $env): array
    {
        return $this->configWith($env)['connections']['reverb']['options'];
    }

    /** The config file, evaluated with a given environment. */
    protected function configWith(array $env): array
    {
        foreach ($env as $key => $value) {
            $value === null ? putenv($key) : putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }

        try {
            return require __DIR__.'/../../../config/broadcasting.php';
        } finally {
            foreach (array_keys($env) as $key) {
                putenv($key);
                unset($_ENV[$key]);
            }
        }
    }

    #[Test]
    public function php_dials_reverb_directly_rather_than_going_out_and_back_in(): void
    {
        $options = $this->optionsWith([
            'REVERB_HOST' => 'hub.thewills.uk',
            'REVERB_PORT' => '443',
            'REVERB_SCHEME' => 'https',
            'REVERB_SERVER_HOST' => '127.0.0.1',
            'REVERB_SERVER_PORT' => '8080',
        ]);

        $this->assertSame('127.0.0.1', $options['host'], 'Not the public hostname.');
        $this->assertSame(8080, $options['port'], 'Not 443.');
        $this->assertSame('http', $options['scheme']);
        $this->assertFalse($options['useTLS']);
    }

    #[Test]
    public function an_address_to_listen_on_is_never_one_to_dial(): void
    {
        // Reverb's own default bind address, which is not a destination.
        $options = $this->optionsWith(['REVERB_SERVER_HOST' => '0.0.0.0']);

        $this->assertSame('127.0.0.1', $options['host']);
    }

    #[Test]
    public function the_server_path_is_carried_with_a_leading_slash(): void
    {
        // Reverb mounts every route under this prefix, the API endpoints
        // included — and the Pusher client concatenates it straight onto
        // host:port, so without the slash the URL is 127.0.0.1:8080reverb.
        $this->assertSame('/reverb', $this->optionsWith(['REVERB_SERVER_PATH' => 'reverb'])['path']);
        $this->assertSame('/reverb', $this->optionsWith(['REVERB_SERVER_PATH' => '/reverb/'])['path']);
        $this->assertSame('', $this->optionsWith(['REVERB_SERVER_PATH' => null])['path']);
    }

    #[Test]
    public function the_client_posts_where_reverb_is_actually_listening(): void
    {
        // The whole bug in one assertion.
        $pusher = new Pusher('key', 'secret', '1', $this->optionsWith([
            'REVERB_HOST' => 'hub.thewills.uk',
            'REVERB_SERVER_HOST' => '127.0.0.1',
            'REVERB_SERVER_PORT' => '8080',
            'REVERB_SERVER_PATH' => 'reverb',
        ]));

        $settings = (new ReflectionClass($pusher))->getProperty('settings')->getValue($pusher);

        $this->assertSame(
            'http://127.0.0.1:8080/reverb',
            $settings['scheme'].'://'.$settings['host'].':'.$settings['port'].$settings['path'],
        );

        // And /apps/{id}/events hangs off that, which is what nginx never saw.
        $this->assertSame('/apps/1', $settings['base_path']);
    }

    #[Test]
    public function the_browser_is_left_going_the_long_way_round(): void
    {
        // The near miss: the page renders these into a meta tag, and it was
        // reading the very keys this fix changed. Pointing PHP at the loopback
        // would have sent the wall's websocket there too.
        $config = $this->configWith([
            'REVERB_HOST' => 'hub.thewills.uk',
            'REVERB_PORT' => '443',
            'REVERB_SCHEME' => 'https',
            'REVERB_SERVER_HOST' => '127.0.0.1',
            'REVERB_SERVER_PORT' => '8080',
            'REVERB_SERVER_PATH' => 'reverb',
        ]);

        $this->assertSame([
            'host' => 'hub.thewills.uk',
            'port' => 443,
            'scheme' => 'https',
            'path' => '/reverb',
        ], $config['connections']['reverb']['browser']);
    }

    #[Test]
    public function the_page_hands_the_browser_the_browsers_values(): void
    {
        $markup = file_get_contents(__DIR__.'/../../../resources/views/partials/head.blade.php');

        $this->assertStringContainsString('reverb.browser.host', $markup);
        $this->assertStringNotContainsString('reverb.options.host', $markup);
    }
}
