<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\Branding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index()
    {
        $db = config('database.connections.'.config('database.default'));

        return Inertia::render('Settings/Index', [
            'company' => [
                'company_name' => Branding::name(),
                'currency' => Setting::get('currency', 'AED'),
                'vat_rate' => (float) Setting::get('vat_rate', 0),
                'cash_opening_balance' => (float) Setting::get('cash_opening_balance', 0),
                'low_cash_threshold' => (float) Setting::get('low_cash_threshold', 500),
                'large_expense_threshold' => (float) Setting::get('large_expense_threshold', 1000),
                'company_address' => Setting::get('company_address', ''),
                'company_trn' => Setting::get('company_trn', ''),
                'company_phone' => Setting::get('company_phone', ''),
                'company_email' => Setting::get('company_email', ''),
                'invoice_footer' => Setting::get('invoice_footer', 'Thank you for your business.'),
                'timezone' => Setting::get('timezone', config('app.timezone')),
            ],
            // Not named 'branding': that key is already shared globally by
            // HandleInertiaRequests, and a page prop would shadow it here.
            'logoSettings' => [
                'logo' => Branding::logo(),
                'is_custom' => Setting::get('company_logo') !== Branding::DEFAULT_LOGO,
            ],
            'timezones' => \DateTimeZone::listIdentifiers(),
            'database' => [
                'connection' => config('database.default'),
                'host' => $db['host'] ?? '',
                'port' => $db['port'] ?? '',
                'database' => $db['database'] ?? '',
                'username' => $db['username'] ?? '',
                // password intentionally never sent to the client
                'password_set' => ! empty($db['password']),
            ],
        ]);
    }

    public function updateCompany(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'max:8'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'cash_opening_balance' => ['required', 'numeric'],
            'low_cash_threshold' => ['required', 'numeric', 'min:0'],
            'large_expense_threshold' => ['required', 'numeric', 'min:0'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'company_trn' => ['nullable', 'string', 'max:50'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_email' => ['nullable', 'string', 'max:100'],
            'invoice_footer' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'string', 'timezone'],
        ]);

        foreach ($data as $key => $value) {
            Setting::put($key, $value);
        }

        // A renamed company must be visible on the response we are about to render.
        Branding::forget();

        config(['app.timezone' => $data['timezone']]);
        date_default_timezone_set($data['timezone']);

        return back()->with('success', 'Company settings saved.');
    }

    /**
     * Replace the company logo shown in the sidebar, on invoices and in PDFs.
     *
     * SVG is deliberately not accepted: it can carry script, and DomPDF cannot
     * render it reliably in the PDF templates anyway.
     */
    public function updateLogo(Request $request)
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], [
            'logo.max' => 'The logo must be 2 MB or smaller.',
            'logo.mimes' => 'The logo must be a PNG, JPG or WEBP image.',
        ]);

        $previous = Setting::get('company_logo');

        $path = $request->file('logo')->store('branding', 'public');

        Setting::put('company_logo', '/storage/'.$path);
        // An uploaded logo is used exactly as supplied — never silhouetted.
        Setting::put('company_logo_invert', '');
        Branding::forget();

        $this->deleteUploadedLogo($previous);

        return back()->with('success', 'Logo updated.');
    }

    /**
     * Drop a custom logo and fall back to the shipped default.
     */
    public function destroyLogo()
    {
        $previous = Setting::get('company_logo');

        Setting::put('company_logo', Branding::DEFAULT_LOGO);
        Setting::put('company_logo_invert', '');
        Branding::forget();

        $this->deleteUploadedLogo($previous);

        return back()->with('success', 'Logo reset to the default.');
    }

    /**
     * Remove a superseded upload. Only ever touches files this app wrote into
     * the public disk's branding/ folder, so a path pointing at a committed
     * asset under public/brand is left alone.
     */
    private function deleteUploadedLogo(?string $path): void
    {
        if (! $path || ! str_starts_with($path, '/storage/branding/')) {
            return;
        }

        Storage::disk('public')->delete(substr($path, strlen('/storage/')));
    }

    /**
     * Test a MySQL connection without persisting anything.
     */
    public function testDatabase(Request $request)
    {
        $data = $this->validateDb($request);

        try {
            $this->makeTempConnection($data)->getPdo();
        } catch (\Throwable $e) {
            return back()->withErrors(['database' => 'Connection failed: '.$e->getMessage()]);
        }

        return back()->with('success', 'Database connection succeeded ✔');
    }

    /**
     * Test, then persist DB credentials to .env. Takes effect next request.
     */
    public function updateDatabase(Request $request)
    {
        $data = $this->validateDb($request);

        try {
            $this->makeTempConnection($data)->getPdo();
        } catch (\Throwable $e) {
            return back()->withErrors(['database' => 'Not saved — connection failed: '.$e->getMessage()]);
        }

        $this->writeEnv([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $data['host'],
            'DB_PORT' => (string) $data['port'],
            'DB_DATABASE' => $data['database'],
            'DB_USERNAME' => $data['username'],
            'DB_PASSWORD' => $data['password'] ?? '',
        ]);
        Artisan::call('config:clear');

        return back()->with('success', 'Database settings saved. They take effect immediately for new requests.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateDb(Request $request): array
    {
        return $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'numeric'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function makeTempConnection(array $data): \Illuminate\Database\Connection
    {
        Config::set('database.connections._probe', [
            'driver' => 'mysql',
            'host' => $data['host'],
            'port' => $data['port'],
            'database' => $data['database'],
            'username' => $data['username'],
            'password' => $data['password'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        DB::purge('_probe');

        return DB::connection('_probe');
    }

    /**
     * Safely update keys in the .env file, preserving everything else.
     *
     * @param  array<string, string>  $values
     */
    private function writeEnv(array $values): void
    {
        $path = base_path('.env');
        $contents = file_get_contents($path);

        foreach ($values as $key => $value) {
            // quote values containing spaces or special chars
            $escaped = preg_match('/\s|#|"/', $value) ? '"'.addslashes($value).'"' : $value;
            if (preg_match("/^{$key}=.*$/m", $contents)) {
                $contents = preg_replace("/^{$key}=.*$/m", "{$key}={$escaped}", $contents);
            } else {
                $contents .= PHP_EOL."{$key}={$escaped}";
            }
        }

        file_put_contents($path, $contents);
    }
}
