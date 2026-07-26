<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index()
    {
        return Inertia::render('Users/Index', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active']),
            'roles' => collect(Role::cases())->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'role' => ['required', Rule::enum(Role::class)],
            'is_active' => ['boolean'],
        ]);

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'is_active' => $data['is_active'] ?? true,
            'email_verified_at' => now(),
        ]);

        return back()->with('success', 'User created.');
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', Password::defaults()],
            'role' => ['required', Rule::enum(Role::class)],
            'is_active' => ['boolean'],
        ]);

        // A super admin cannot demote or deactivate themselves (avoid lock-out).
        if ($user->id === $request->user()->id) {
            $data['role'] = $user->role->value;
            $data['is_active'] = true;
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'is_active' => $data['is_active'] ?? $user->is_active,
        ]);
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return back()->with('success', 'User updated.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }
        if ($user->hasRole(Role::SUPER_ADMIN) && User::where('role', Role::SUPER_ADMIN->value)->count() <= 1) {
            return back()->withErrors(['user' => 'Cannot delete the last Super Admin.']);
        }

        $user->delete();

        return back()->with('success', 'User deleted.');
    }
}
