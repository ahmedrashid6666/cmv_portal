<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the company branding shown across the UI, PDFs and link previews.
 *
 * Branding is a runtime business setting (Settings page), not a deployment
 * concern, so it lives in the `settings` table. The constants below are only
 * the fallback for a fresh install with no settings rows yet — existing
 * installs are pinned to their own branding by the 2026_08_18 migration.
 */
class Branding
{
    public const DEFAULT_NAME = 'Hark Creation';

    /** Shown in the browser; an SVG badge that reads on both light and dark backgrounds. */
    public const DEFAULT_LOGO = '/brand/hark-logo.svg';

    /** DomPDF renders SVG unreliably, so PDFs embed this raster twin instead. */
    public const DEFAULT_LOGO_RASTER = '/brand/hark-logo.png';

    public const DEFAULT_OG_IMAGE = '/brand/hark-og-image.png';

    /** Per-request memo — a single PDF asks for branding once per header. */
    private static ?array $cache = null;

    /**
     * Every branding field, ready to hand to a view.
     *
     * @return array{name: string, logo: string, og_image: string, logo_invert_on_dark: bool, address: string, phone: string, email: string, trn: string, footer: string}
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        // Also runs during `migrate` on a fresh install, before the table exists.
        try {
            if (! Schema::hasTable('settings')) {
                return self::defaults();
            }
        } catch (\Throwable $e) {
            return self::defaults();
        }

        return self::$cache = [
            'name' => Setting::get('company_name') ?: self::DEFAULT_NAME,
            'logo' => Setting::get('company_logo') ?: self::DEFAULT_LOGO,
            'og_image' => Setting::get('company_og_image') ?: self::DEFAULT_OG_IMAGE,
            'logo_invert_on_dark' => (bool) Setting::get('company_logo_invert', false),
            'address' => Setting::get('company_address', ''),
            'phone' => Setting::get('company_phone', ''),
            'email' => Setting::get('company_email', ''),
            'trn' => Setting::get('company_trn', ''),
            'footer' => Setting::get('invoice_footer', 'Thank you for your business.'),
        ];
    }

    public static function name(): string
    {
        return self::all()['name'];
    }

    /**
     * The product name for the browser tab and link previews.
     *
     * APP_NAME stays the source of truth so each deployment can title itself,
     * but an install that never set it would otherwise advertise "Laravel".
     */
    public static function appName(): string
    {
        $configured = config('app.name');

        return (! $configured || $configured === 'Laravel')
            ? self::name().' Accounts'
            : $configured;
    }

    /** Web path to the logo, for <img src> in the browser. */
    public static function logo(): string
    {
        return self::all()['logo'];
    }

    /**
     * The logo as a base64 data URI so DomPDF can embed it without a network
     * fetch. Falls back to the default raster when the configured logo is an
     * SVG or missing on disk, because DomPDF cannot be trusted with either.
     */
    public static function logoDataUri(): ?string
    {
        $candidates = [self::logo(), self::DEFAULT_LOGO_RASTER];

        foreach ($candidates as $path) {
            if (str_ends_with(strtolower($path), '.svg')) {
                continue;
            }

            $file = public_path(ltrim($path, '/'));

            if (is_file($file) && ($contents = @file_get_contents($file)) !== false) {
                $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'webp' => 'image/webp',
                    default => 'image/png',
                };

                return 'data:'.$mime.';base64,'.base64_encode($contents);
            }
        }

        return null;
    }

    /** Drops the memo so a settings save is visible to the next read in the same request. */
    public static function forget(): void
    {
        self::$cache = null;
    }

    private static function defaults(): array
    {
        return [
            'name' => self::DEFAULT_NAME,
            'logo' => self::DEFAULT_LOGO,
            'og_image' => self::DEFAULT_OG_IMAGE,
            'logo_invert_on_dark' => false,
            'address' => '',
            'phone' => '',
            'email' => '',
            'trn' => '',
            'footer' => 'Thank you for your business.',
        ];
    }
}
