<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Forge/nginx terminates TLS and forwards over plain HTTP. Without
        // this, Request::url() reports http:// and the display-pairing
        // redirect downgrades the scheme — which drops the Secure pairing
        // cookie and leaves the iPad stuck on "not paired".
        $middleware->trustProxies(at: '*');

        // Postmark cannot present a CSRF token; the route authenticates with
        // the shared secret in VerifyPostmarkWebhook instead.
        $middleware->validateCsrfTokens(except: ['webhooks/postmark/*', 'webhooks/postmark']);

        $middleware->alias([
            'display.token' => \App\Http\Middleware\EnsureDisplayToken::class,
            'postmark' => \App\Http\Middleware\VerifyPostmarkWebhook::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
