<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One-click sign-in for the public sales demo.
 *
 * Only reachable when config('demo.enabled') is true, which is set per
 * deployment in .env and is off everywhere by default. The route itself is not
 * registered without it, so this cannot be reached on a real installation even
 * if the code is deployed there.
 */
class DemoLoginController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless(config('demo.enabled'), 404);

        $user = User::query()
            ->where('email', config('demo.user_email'))
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return back()->withErrors([
                'email' => 'The demo account is not set up on this instance.',
            ]);
        }

        // Belt and braces: even if someone points demo.user_email at a
        // privileged account, refuse to hand out a passwordless admin session.
        if (in_array($user->role, [Role::SUPER_ADMIN, Role::ADMIN], true)) {
            return back()->withErrors([
                'email' => 'The demo account must not be an administrator.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
