<?php

namespace App\Providers;

use App\Models\Setting;
use App\Support\Branding;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
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
        $this->shareBrandingWithViews();
    }

    /**
     * PDFs and exports all print the company name and logo in their header.
     * Binding it once here keeps every template white-label by default — no
     * controller has to remember to pass it through.
     */
    private function shareBrandingWithViews(): void
    {
        // The Inertia root view needs the name and asset paths for the tab
        // title, favicon and link preview.
        View::composer('app', function ($view) {
            $view->with('company', Branding::all())
                ->with('appName', Branding::appName());
        });

        View::composer([
            'exports.table',
            'reports.pdf',
            'invoices.pdf',
            'final-calculation.pdf',
            'cash-count.pdf',
            'bank-statement.pdf',
        ], function ($view) {
            $view->with('company', [
                ...Branding::all(),
                // Only PDFs need the embedded copy; it is too heavy to ship
                // to the browser on every Inertia response.
                'logo_data_uri' => Branding::logoDataUri(),
            ]);
        });
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
