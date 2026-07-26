<?php

use App\Enums\Role;
use App\Models\User;

it('lets a super admin create a user', function () {
    $this->actingAs(User::factory()->role(Role::SUPER_ADMIN)->create())
        ->post(route('users.store'), [
            'name' => 'New Accountant',
            'email' => 'acct@cmv.com',
            'password' => 'Password123!',
            'role' => 'accountant',
            'is_active' => true,
        ])->assertRedirect();

    $u = User::where('email', 'acct@cmv.com')->first();
    expect($u)->not->toBeNull()->and($u->role)->toBe(Role::ACCOUNTANT);
});

it('forbids a non super admin from managing users', function () {
    $this->actingAs(User::factory()->role(Role::ADMIN)->create())
        ->get(route('users.index'))->assertForbidden();

    $this->actingAs(User::factory()->role(Role::ACCOUNTANT)->create())
        ->post(route('users.store'), [])->assertForbidden();
});

it('prevents deleting the last super admin', function () {
    $admin = User::factory()->role(Role::SUPER_ADMIN)->create();

    $this->actingAs($admin)
        ->delete(route('users.destroy', $admin))
        ->assertSessionHasErrors('user');

    expect(User::whereKey($admin->id)->exists())->toBeTrue();
});

it('cannot change your own role', function () {
    $admin = User::factory()->role(Role::SUPER_ADMIN)->create();

    $this->actingAs($admin)->put(route('users.update', $admin), [
        'name' => $admin->name,
        'email' => $admin->email,
        'role' => 'read_only',
        'is_active' => false,
    ])->assertRedirect();

    $admin->refresh();
    expect($admin->role)->toBe(Role::SUPER_ADMIN)->and($admin->is_active)->toBeTrue();
});

it('blocks a deactivated user from logging in', function () {
    $user = User::factory()->role(Role::ACCOUNTANT)->create([
        'password' => bcrypt('secret123'),
        'is_active' => false,
    ]);

    $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
