<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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
        Vite::prefetch(concurrency: 3);
        $this->applyConfiguredTimezone();
    }

    /**
     * The app's timezone is a runtime-configurable business setting (Settings
     * page), not a deployment concern, so it lives in the `settings` table
     * rather than .env. Guarded because this also runs during `migrate` on a
     * fresh install, before that table exists.
     */
    private function applyConfiguredTimezone(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        $timezone = Setting::get('timezone', config('app.timezone'));
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }
}
