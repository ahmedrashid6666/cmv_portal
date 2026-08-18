<?php

use App\Enums\Role;
use App\Models\User;
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

it('does not register the demo route when demo mode is off', function () {
    withDemoMode(false, function () {
        expect(config('demo.enabled'))->toBeFalse()
            ->and(Route::has('demo.login'))->toBeFalse();

        // Not merely hidden — there is nothing there to reach.
        $this->post('/demo-login')->assertNotFound();
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

        expect(Route::has('demo.login'))->toBeTrue();

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
