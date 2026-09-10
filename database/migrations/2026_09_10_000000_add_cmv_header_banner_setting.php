<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sets the wide letterhead banner (public/brand/cmv-header-banner.jpg) as the
 * PDF header for CMV-branded installs only — identified by already having
 * `company_logo` pinned to the CMV logo (see the 2026_08_18 pin migration).
 * A fresh install or a differently-branded one (e.g. the Hark Creation demo)
 * gets no `company_header_banner`, so its PDFs keep the text-based header.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $isCmvBranded = DB::table('settings')
            ->where('key', 'company_logo')
            ->where('value', '/brand/cmv-logo.png')
            ->exists();

        if (! $isCmvBranded) {
            return;
        }

        if (DB::table('settings')->where('key', 'company_header_banner')->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => 'company_header_banner',
            'value' => '/brand/cmv-header-banner.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('key', 'company_header_banner')
            ->where('value', '/brand/cmv-header-banner.jpg')
            ->delete();
    }
};
