<?php

namespace App\Models;

use App\Enums\Role as RoleSlug;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var array<int, string>|null per-request cache of permission keys */
    private ?array $permissionKeys = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function pcBuilds(): HasMany
    {
        return $this->hasMany(PcBuild::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(RoleSlug|string $role): bool
    {
        $slug = $role instanceof RoleSlug ? $role->value : $role;

        return $this->roles->contains('slug', $slug);
    }

    public function assignRole(RoleSlug|string $role): void
    {
        $slug = $role instanceof RoleSlug ? $role->value : $role;

        $this->roles()->syncWithoutDetaching([Role::where('slug', $slug)->firstOrFail()->id]);
        $this->unsetRelation('roles');
        $this->permissionKeys = null;
    }

    /** @return array<int, string> every permission key granted through any of the user's roles */
    public function permissionKeys(): array
    {
        return $this->permissionKeys ??= Permission::query()
            ->whereHas('roles.users', fn ($q) => $q->whereKey($this->getKey()))
            ->pluck('key')
            ->all();
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissionKeys(), true);
    }
}
