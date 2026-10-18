<?php

namespace App\Providers;

use App\Services\PortalSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme(parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https');
        }
        try {
            PortalSettings::apply();
        } catch (QueryException $e) {
            if (! app()->runningInConsole()) {
                throw $e;
            }
        }
    }
}
