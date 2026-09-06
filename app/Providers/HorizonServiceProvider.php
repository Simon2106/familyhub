<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * Every login belongs to the one household and there is no public signup,
     * so any authenticated user is a parent. The generated default is an empty
     * allowlist, which locks everyone out of /horizon in production.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null) => $user !== null);
    }
}
