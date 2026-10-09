<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Role as RoleSlug;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRolesRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Who can do what. The routes require the users.manage permission (admins).
 * Two guard rails keep the shop from locking itself out: nobody can change their own roles or deactivate themselves,
 * and the last active admin can never lose the admin role or be deactivated.
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(Role::pluck('slug')->all())],
            'active' => ['nullable', 'boolean'],
        ]);
        $term = isset($filters['q']) ? '%'.addcslashes(trim($filters['q']), '%_\\').'%' : null;

        $users = User::query()
            ->with('roles')
            ->withCount('orders')
            ->when($term, fn ($q) => $q->where(fn ($w) => $w->where('email', 'ilike', $term)->orWhere('name', 'ilike', $term)))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->whereHas('roles', fn ($r) => $r->where('slug', $role)))
            ->when(isset($filters['active']), fn ($q) => $q->where('is_active', (bool) $filters['active']))
            ->latest()->latest('id')
            ->paginate(20);

        return AdminUserResource::collection($users);
    }

    public function show(User $user): AdminUserResource
    {
        return new AdminUserResource($user->load('roles')->loadCount('orders'));
    }

    public function updateRoles(UpdateUserRolesRequest $request, User $user): AdminUserResource
    {
        $slugs = array_values(array_unique($request->validated('roles')));

        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['roles' => 'You cannot change your own roles. Ask another admin.']);
        }

        if ($user->hasRole(RoleSlug::Admin) && ! in_array(RoleSlug::Admin->value, $slugs, true) && $this->activeAdmins() <= 1) {
            throw ValidationException::withMessages(['roles' => 'This is the last active admin, so the admin role cannot be removed.']);
        }

        $old = $user->roles->pluck('slug')->sort()->values()->all();
        $user->roles()->sync(Role::whereIn('slug', $slugs)->pluck('id'));
        $new = collect($slugs)->sort()->values()->all();

        if ($old !== $new) {
            $this->audit->log('user.roles_changed', $request->user(), $user, ['roles' => $old], ['roles' => $new]);
        }

        return new AdminUserResource($user->fresh()->load('roles')->loadCount('orders'));
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): AdminUserResource
    {
        $active = $request->boolean('is_active');

        if (! $active && $user->is($request->user())) {
            throw ValidationException::withMessages(['is_active' => 'You cannot deactivate yourself.']);
        }

        if (! $active && $user->is_active && $user->hasRole(RoleSlug::Admin) && $this->activeAdmins() <= 1) {
            throw ValidationException::withMessages(['is_active' => 'This is the last active admin, so it cannot be deactivated.']);
        }

        if ($user->is_active !== $active) {
            $user->forceFill(['is_active' => $active])->save();

            if (! $active) {
                // End whatever they are signed in with right now (the middleware also signs them out on their next request).
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->tokens()->delete();
            }

            $this->audit->log($active ? 'user.activated' : 'user.deactivated', $request->user(), $user);
        }

        return new AdminUserResource($user->fresh()->load('roles')->loadCount('orders'));
    }

    private function activeAdmins(): int
    {
        return User::where('is_active', true)->whereHas('roles', fn ($r) => $r->where('slug', RoleSlug::Admin->value))->count();
    }
}
