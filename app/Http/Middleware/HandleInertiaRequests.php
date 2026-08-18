<?php

namespace App\Http\Middleware;

use App\Support\Branding;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            // Every screen prints the company name or logo somewhere, so it
            // rides along on the shared props rather than per-page.
            'branding' => fn () => [
                'name' => Branding::name(),
                'logo' => Branding::logo(),
                'logoInvertOnDark' => Branding::all()['logo_invert_on_dark'],
            ],
            'demo' => ['enabled' => (bool) config('demo.enabled')],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
