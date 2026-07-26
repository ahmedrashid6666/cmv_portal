<?php

use App\Enums\Role;
use App\Models\User;

it('exposes a typed role and casts it', function () {
    $u = User::factory()->create(['role' => Role::ACCOUNTANT->value]);

    expect($u->role)->toBe(Role::ACCOUNTANT)
        ->and($u->hasRole(Role::ACCOUNTANT))->toBeTrue()
        ->and($u->hasRole(Role::ADMIN))->toBeFalse();
});

it('checks membership in a set of roles', function () {
    $u = User::factory()->role(Role::ADMIN)->create();

    expect($u->hasAnyRole(Role::SUPER_ADMIN, Role::ADMIN))->toBeTrue()
        ->and($u->hasAnyRole(Role::ACCOUNTANT, Role::READ_ONLY))->toBeFalse();
});

it('defaults new users to read_only', function () {
    $u = User::factory()->create();

    expect($u->role)->toBe(Role::READ_ONLY);
});

it('issues sanctum tokens', function () {
    $u = User::factory()->create();
    $token = $u->createToken('test');

    expect($token->plainTextToken)->toBeString()->not->toBeEmpty();
});
