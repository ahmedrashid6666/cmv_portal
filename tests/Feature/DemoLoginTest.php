<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/**
 * The demo login hands out a session with no password, so the thing worth
 * testing hardest is that it does not exist unless a deployment asks for it.
 */
function withDemoMode(bool $enabled, Closure $test): void
{
    putenv('DEMO_MODE='.($enabled ? 'true' : 'false'));

    // Routes are registered at boot from config, so the app has to be rebuilt
    // for the flag to take effect — which is precisely the guarantee here.
    // Rebuilding swaps in a fresh :memory: database, so the schema goes with
    // it and has to be laid down again.
    test()->refreshApplication();
    test()->artisan('migrate');

    try {
        $test();
    } finally {
        putenv('DEMO_MODE');
    }
}

it('404s the demo login when demo mode is off', function () {
    withDemoMode(false, function () {
        expect(config('demo.enabled'))->toBeFalse();

        $this->post('/demo-login')->assertNotFound();
        $this->assertGuest();
    });
});

it('404s the demo login even when the route table was cached with demo mode on', function () {
    // The route is registered unconditionally and gated in the controller
    // precisely so this holds. A config-conditional registration would be
    // frozen by route:cache and could not react to the flag changing —
    // which once left the button rendering against a 404 route.
    withDemoMode(true, function () {
        Artisan::call('route:cache');

        try {
            putenv('DEMO_MODE=false');
            test()->refreshApplication();
            test()->artisan('migrate');

            expect(config('demo.enabled'))->toBeFalse();
            $this->post('/demo-login')->assertNotFound();
            $this->assertGuest();
        } finally {
            Artisan::call('route:clear');
        }
    });
});

it('keeps the demo route in the cached table regardless of the flag', function () {
    // The pair of assertions across this test and the one above is the whole
    // fix: the route is always compiled in, and whether it does anything is
    // decided per request. Previously the registration was config-conditional,
    // so route:cache froze it — the button rendered from fresh config against
    // a route that no longer existed, and clicking it did nothing.
    withDemoMode(false, function () {
        Artisan::call('route:cache');

        try {
            expect(Route::has('demo.login'))->toBeTrue()
                ->and(config('demo.enabled'))->toBeFalse();
        } finally {
            Artisan::call('route:clear');
        }
    });
});

it('does not offer the button when demo mode is off', function () {
    withDemoMode(false, function () {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Explore the demo');
    });
});

it('signs in the demo account when demo mode is on', function () {
    withDemoMode(true, function () {
        $this->seed(\Database\Seeders\DefaultDataSeeder::class);
        $this->seed(\Database\Seeders\DemoDataSeeder::class);

        $this->post(route('demo.login'))->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        expect(auth()->user()->email)->toBe(config('demo.user_email'))
            ->and(auth()->user()->role)->toBe(Role::ACCOUNTANT);
    });
});

it('refuses to hand out a passwordless administrator session', function () {
    withDemoMode(true, function () {
        $this->seed(\Database\Seeders\DefaultDataSeeder::class);

        // Someone points demo.user_email at a privileged account.
        User::factory()->create([
            'email' => config('demo.user_email'),
            'role' => Role::SUPER_ADMIN->value,
            'is_active' => true,
        ]);

        $this->from(route('login'))
            ->post(route('demo.login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    });
});

it('reports clearly when the demo account is missing', function () {
    withDemoMode(true, function () {
        $this->from(route('login'))
            ->post(route('demo.login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    });
});
