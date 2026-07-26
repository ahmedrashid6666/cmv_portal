<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index()
    {
        $db = config('database.connections.'.config('database.default'));

        return Inertia::render('Settings/Index', [
            'company' => [
                'company_name' => Setting::get('company_name', 'CMV Shipping'),
                'currency' => Setting::get('currency', 'AED'),
                'vat_rate' => (float) Setting::get('vat_rate', 0),
                'cash_opening_balance' => (float) Setting::get('cash_opening_balance', 0),
                'low_cash_threshold' => (float) Setting::get('low_cash_threshold', 500),
                'large_expense_threshold' => (float) Setting::get('large_expense_threshold', 1000),
            ],
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
        ]);

        foreach ($data as $key => $value) {
            Setting::put($key, $value);
        }

        return back()->with('success', 'Company settings saved.');
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
