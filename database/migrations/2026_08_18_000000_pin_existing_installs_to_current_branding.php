<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branding used to be hardcoded as "CMV Shipping" with a logo at /logo.png.
 * It is now a setting, defaulting to Hark Creation on a fresh install.
 *
 * Without this migration, deploying that change to an existing install would
 * silently swap its logo for the new default. So: any install that already has
 * settings rows is an existing one — pin it to the logo it was already showing
 * before it ever gets a chance to fall back.
 */
return new class extends Migration
{
    /** Snapshots of the assets the hardcoded build shipped, kept under public/brand. */
    private const LEGACY_ASSETS = [
        'company_logo' => '/brand/cmv-logo.png',
        'company_og_image' => '/brand/cmv-og-image.png',
        // That logo is drawn for a light background; the sidebar flipped it
        // to a white silhouette. Keep doing that for these installs.
        'company_logo_invert' => '1',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        // A fresh install has no settings yet — the seeder gives it the new
        // default. Only a pre-existing install needs pinning.
        $isExistingInstall = DB::table('settings')->where('key', 'company_name')->exists();

        if (! $isExistingInstall) {
            return;
        }

        foreach (self::LEGACY_ASSETS as $key => $path) {
            if (DB::table('settings')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('settings')->insert([
                'key' => $key,
                'value' => $path,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::LEGACY_ASSETS as $key => $path) {
            DB::table('settings')
                ->where('key', $key)
                ->where('value', $path)
                ->delete();
        }
    }
};
