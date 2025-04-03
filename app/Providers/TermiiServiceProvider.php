<?php

namespace App\Providers;

use App\Services\TermiiService;
use Illuminate\Support\ServiceProvider;

class TermiiServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(TermiiService::class, function ($app) {
            return new TermiiService(
                config('services.termii.api_key'),
                config('services.termii.api_url'),
                config('services.termii.sender_id')
            );
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
