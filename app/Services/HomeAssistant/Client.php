<?php

namespace App\Services\HomeAssistant;

use App\Exceptions\HomeAssistantException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Transport only. Knows how to reach Home Assistant, and nothing about lights.
 *
 * The base URL is used exactly as configured. Home Assistant is famously on
 * 8123, but it is behind a reverse proxy on 80 here, and a driver that helpfully
 * appends a port is a driver that cannot talk to this house at all.
 */
class Client
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $token = null,
        protected ?int $timeout = null,
    ) {
        $this->baseUrl = $baseUrl ?? config('familyhub.homeassistant.url');
        $this->token = $token ?? config('familyhub.homeassistant.token');
        $this->timeout = $timeout ?? config('familyhub.homeassistant.timeout');
    }

    public function isConfigured(): bool
    {
        return filled($this->baseUrl) && filled($this->token);
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        return $this->send(fn (PendingRequest $r) => $r->get($this->url($path)), $path);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int|string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->send(fn (PendingRequest $r) => $r->post($this->url($path), $payload), $path);
    }

    /** The template API answers with plain text, not JSON. */
    public function render(string $template): string
    {
        $response = $this->request()->post($this->url('/api/template'), ['template' => $template]);

        if ($response->failed()) {
            throw new HomeAssistantException($this->explain($response->status(), '/api/template'));
        }

        return $response->body();
    }

    /**
     * The websocket address for the same install.
     *
     * Derived from the base URL rather than configured separately, so there is
     * one place to get the host wrong. Scheme swaps, everything else — port
     * included, or its absence — is left exactly alone.
     */
    public function websocketUrl(): string
    {
        $url = rtrim((string) $this->baseUrl, '/');

        $ws = match (parse_url($url, PHP_URL_SCHEME)) {
            'https' => preg_replace('#^https://#i', 'wss://', $url),
            'http' => preg_replace('#^http://#i', 'ws://', $url),
            default => throw new HomeAssistantException('HA_URL must start with http:// or https://.'),
        };

        return $ws.'/api/websocket';
    }

    public function token(): string
    {
        return (string) $this->token;
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     * @return array<int|string, mixed>
     */
    protected function send(callable $send, string $path): array
    {
        try {
            $response = $send($this->request());
        } catch (HomeAssistantException $e) {
            throw $e;
        } catch (Throwable $e) {
            // A Pi that is off, or a hostname that does not resolve. Said
            // plainly, because this is shown on a wall in a kitchen.
            throw new HomeAssistantException('Home Assistant could not be reached: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new HomeAssistantException($this->explain($response->status(), $path));
        }

        return $response->json() ?? [];
    }

    protected function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new HomeAssistantException('No HA_URL and HA_TOKEN are set, so Home Assistant cannot be reached.');
        }

        return Http::withToken($this->token)
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout(min($this->timeout, 5));
    }

    protected function url(string $path): string
    {
        return rtrim((string) $this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    protected function explain(int $status, string $path): string
    {
        return match ($status) {
            401, 403 => 'Home Assistant refused the token. Create a new long-lived access token and put it in HA_TOKEN.',
            404 => "Home Assistant has no {$path}. Check HA_URL points at the base address, not at a dashboard.",
            default => "Home Assistant answered {$status} for {$path}.",
        };
    }
}
