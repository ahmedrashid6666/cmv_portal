<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'role:super_admin,admin'])
        ->get('/_role_test', fn () => 'ok');
});

it('allows a user whose role is in the list', function () {
    $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->get('/_role_test')
        ->assertOk();
});

it('forbids a user whose role is not in the list', function () {
    $this->actingAs(User::factory()->role(Role::ACCOUNTANT)->create())
        ->get('/_role_test')
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    $this->get('/_role_test')->assertRedirect('/login');
});
