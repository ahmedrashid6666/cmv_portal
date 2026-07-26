<?php

namespace App\Models;

use App\Models\Concerns\Auditable;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return $this->name.' ('.$this->email.')';
    }

    /**
     * @return array<int, string>
     */
    public function auditExclude(): array
    {
        return ['password', 'remember_token', 'email_verified_at'];
    }

    public function hasRole(Role $role): bool
    {
        return $this->role === $role;
    }

    /**
     * True when the user's role is any of the given roles.
     */
    public function hasAnyRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
