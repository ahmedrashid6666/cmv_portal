<?php

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Branding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Branding::forget();
});

afterEach(function () {
    Branding::forget();
});

/*
|--------------------------------------------------------------------------
| Defaults
|--------------------------------------------------------------------------
*/

it('falls back to the shipped brand when nothing is configured', function () {
    expect(Branding::name())->toBe('Hark Creation')
        ->and(Branding::logo())->toBe(Branding::DEFAULT_LOGO);
});

it('prefers the configured company over the default', function () {
    Setting::put('company_name', 'Gulf Freight LLC');
    Setting::put('company_logo', '/brand/cmv-logo.png');
    Branding::forget();

    expect(Branding::name())->toBe('Gulf Freight LLC')
        ->and(Branding::logo())->toBe('/brand/cmv-logo.png');
});

it('titles the app from the brand when APP_NAME was never set', function () {
    config()->set('app.name', 'Laravel');

    expect(Branding::appName())->toBe('Hark Creation Accounts');
});

it('keeps APP_NAME as the title when a deployment sets one', function () {
    config()->set('app.name', 'CMV Shipping Accounts');

    expect(Branding::appName())->toBe('CMV Shipping Accounts');
});

/*
|--------------------------------------------------------------------------
| PDF embedding
|--------------------------------------------------------------------------
*/

it('embeds the logo as a raster data URI that DomPDF can render', function () {
    $uri = Branding::logoDataUri();

    expect($uri)->toStartWith('data:image/png;base64,');
});

it('substitutes the raster twin when the configured logo is an SVG', function () {
    Setting::put('company_logo', '/brand/hark-logo.svg');
    Branding::forget();

    $raster = base64_encode(file_get_contents(public_path('brand/hark-logo.png')));

    expect(Branding::logoDataUri())->toBe('data:image/png;base64,'.$raster);
});

/*
|--------------------------------------------------------------------------
| Templates carry no hardcoded brand
|--------------------------------------------------------------------------
*/

it('prints the configured company name on report and export PDFs', function () {
    Setting::put('company_name', 'Gulf Freight LLC');
    Branding::forget();

    $report = view('reports.pdf', [
        'report' => ['title' => 'Daily Report', 'columns' => ['Date'], 'rows' => [], 'totals' => []],
    ])->render();

    $export = view('exports.table', [
        'title' => 'Transactions',
        'columns' => ['Date'],
        'rows' => [],
        'align' => [false],
        'hasTotals' => false,
        'range' => null,
    ])->render();

    expect($report)->toContain('Gulf Freight LLC')
        ->and($export)->toContain('Gulf Freight LLC')
        ->and($report)->not->toContain('CMV')
        ->and($export)->not->toContain('CMV');
});

it('serves the configured brand in the page head', function () {
    // The tab title comes from APP_NAME; everything else comes from the brand.
    config()->set('app.name', 'Gulf Freight Accounts');
    Setting::put('company_name', 'Gulf Freight LLC');
    Branding::forget();

    $html = $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Gulf Freight LLC')
        ->and($html)->not->toContain('CMV Shipping');
});

it('hands the sidebar its brand through the shared Inertia props', function () {
    Setting::put('company_name', 'Gulf Freight LLC');
    Setting::put('company_logo', '/brand/cmv-logo.png');
    Setting::put('company_logo_invert', '1');
    Branding::forget();

    $html = $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    preg_match('/data-page="([^"]*)"/', $html, $m);
    $page = json_decode(html_entity_decode($m[1]), true);

    expect($page['props']['branding'])->toBe([
        'name' => 'Gulf Freight LLC',
        'logo' => '/brand/cmv-logo.png',
        'logoInvertOnDark' => true,
    ]);
});

it('leaves no hardcoded company name in templates or components', function () {
    $roots = ['resources/views', 'resources/js', 'app'];
    $offenders = [];

    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! in_array($file->getExtension(), ['php', 'jsx', 'js'], true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // Comments may still describe the CMV workbook the importer parses;
            // what must not survive is a brand string that reaches the screen.
            foreach (["'CMV", '"CMV', '>CMV', 'CMV Shipping<'] as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
                    break;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('ships a brand-free frontend bundle', function () {
    // Vite inlines import.meta.env at build time, so a VITE_APP_NAME baked into
    // the committed bundle would title every deployment after whoever last ran
    // `npm run build`. This caught exactly that on the first demo deploy.
    $assets = glob(public_path('build/assets/*.js'));

    expect($assets)->not->toBeEmpty();

    $offenders = array_values(array_filter(
        $assets,
        fn ($file) => str_contains(file_get_contents($file), 'CMV')
    ));

    expect(array_map('basename', $offenders))->toBe([]);
});

it('renders the app name for client-side page titles', function () {
    config()->set('app.name', 'Gulf Freight Accounts');

    $html = $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('<meta name="app-name" content="Gulf Freight Accounts">');
});

/*
|--------------------------------------------------------------------------
| Existing deployments keep their branding
|--------------------------------------------------------------------------
*/

it('pins an install that already has settings to the branding it was showing', function () {
    DB::table('settings')->delete();
    DB::table('settings')->insert([
        'key' => 'company_name', 'value' => 'CMV Shipping',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    migration()->up();

    expect(Setting::get('company_logo'))->toBe('/brand/cmv-logo.png')
        ->and(Setting::get('company_og_image'))->toBe('/brand/cmv-og-image.png')
        ->and(Setting::get('company_logo_invert'))->toBe('1');

    Branding::forget();

    expect(Branding::name())->toBe('CMV Shipping')
        ->and(Branding::logo())->toBe('/brand/cmv-logo.png')
        ->and(Branding::all()['logo_invert_on_dark'])->toBeTrue();
});

it('leaves a fresh install on the new default', function () {
    DB::table('settings')->delete();

    migration()->up();

    expect(DB::table('settings')->count())->toBe(0);

    Branding::forget();

    expect(Branding::name())->toBe('Hark Creation')
        ->and(Branding::all()['logo_invert_on_dark'])->toBeFalse();
});

it('does not overwrite branding an install has already chosen', function () {
    DB::table('settings')->delete();
    foreach ([['company_name', 'Gulf Freight LLC'], ['company_logo', '/storage/branding/theirs.png']] as [$key, $value]) {
        DB::table('settings')->insert([
            'key' => $key, 'value' => $value,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    migration()->up();

    expect(Setting::get('company_logo'))->toBe('/storage/branding/theirs.png');
});

/*
|--------------------------------------------------------------------------
| Logo upload
|--------------------------------------------------------------------------
*/

it('stores an uploaded logo and points branding at it', function () {
    Storage::fake('public');

    $this->actingAs(User::factory()->role(Role::SUPER_ADMIN)->create())
        ->post(route('settings.logo'), ['logo' => UploadedFile::fake()->image('brand.png', 200, 200)])
        ->assertRedirect();

    $stored = Setting::get('company_logo');

    expect($stored)->toStartWith('/storage/branding/');
    Storage::disk('public')->assertExists(substr($stored, strlen('/storage/')));
});

it('rejects an SVG logo, which could carry script', function () {
    Storage::fake('public');

    $this->actingAs(User::factory()->role(Role::SUPER_ADMIN)->create())
        ->from(route('settings.index'))
        ->post(route('settings.logo'), ['logo' => UploadedFile::fake()->create('brand.svg', 10, 'image/svg+xml')])
        ->assertSessionHasErrors('logo');

    expect(Setting::get('company_logo'))->toBeNull();
});

it('restores the default logo and deletes the upload it replaced', function () {
    Storage::fake('public');

    $admin = User::factory()->role(Role::SUPER_ADMIN)->create();

    $this->actingAs($admin)
        ->post(route('settings.logo'), ['logo' => UploadedFile::fake()->image('brand.png', 200, 200)]);

    $uploaded = substr(Setting::get('company_logo'), strlen('/storage/'));

    $this->actingAs($admin)->delete(route('settings.logo.destroy'))->assertRedirect();

    expect(Setting::get('company_logo'))->toBe(Branding::DEFAULT_LOGO);
    Storage::disk('public')->assertMissing($uploaded);
});

function migration(): object
{
    return require database_path('migrations/2026_08_18_000000_pin_existing_installs_to_current_branding.php');
}
