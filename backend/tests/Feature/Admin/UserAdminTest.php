<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

describe('the users list', function () {
    it('is for admins only', function () {
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->actingAs(shopper())->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs(withRole('staff'))->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs(withRole('admin'))->getJson('/api/admin/users')->assertOk();
    });

    it('shows roles, status and how many orders each user has', function () {
        $customer = shopper();
        paidOrder($customer);
        paidOrder($customer);
        $admin = withRole('admin');

        $rows = collect($this->actingAs($admin)->getJson('/api/admin/users')->assertOk()->json('data'))->keyBy('email');

        expect($rows[$customer->email]['roles'])->toBe(['customer'])
            ->and($rows[$customer->email]['orders_count'])->toBe(2)
            ->and($rows[$customer->email]['is_active'])->toBeTrue()
            ->and($rows[$admin->email]['roles'])->toBe(['admin'])
            ->and($rows[$admin->email])->not->toHaveKey('password');
    });

    it('filters by role and status, and searches by name or email', function () {
        $siti = User::factory()->create(['name' => 'Siti Aminah', 'email' => 'siti@example.test']);
        $siti->assignRole('customer');
        $off = User::factory()->create(['is_active' => false]);
        $off->assignRole('customer');
        $staff = withRole('staff');
        $admin = withRole('admin');

        $this->actingAs($admin)->getJson('/api/admin/users?role=staff')->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', $staff->email);
        $this->actingAs($admin)->getJson('/api/admin/users?active=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', $off->email);
        $this->actingAs($admin)->getJson('/api/admin/users?q=aminah')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/admin/users?q=siti@')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/admin/users?role=wizard')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/admin/users?q=%25')->assertJsonCount(0, 'data');
    });

    it('does not run more queries for more users (no N+1)', function () {
        $admin = withRole('admin');
        shopper();
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();

        DB::enableQueryLog();
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();
        $before = count(DB::getQueryLog());
        foreach (range(1, 5) as $_) {
            shopper();
        }
        DB::flushQueryLog();
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();

        expect(count(DB::getQueryLog()))->toBe($before);
    });

    it('shows one user', function () {
        $customer = shopper();

        $this->actingAs(withRole('admin'))->getJson("/api/admin/users/{$customer->id}")->assertOk()
            ->assertJsonPath('data.email', $customer->email)->assertJsonPath('data.orders_count', 0);
    });
});

describe('changing roles', function () {
    it('gives someone staff access, and takes effect straight away', function () {
        $user = shopper();
        $admin = withRole('admin');
        $this->actingAs($user)->getJson('/api/admin/orders')->assertForbidden();

        $this->actingAs($admin)->putJson("/api/admin/users/{$user->id}/roles", ['roles' => ['customer', 'staff']])->assertOk()
            ->assertJsonPath('data.roles', ['customer', 'staff']);

        auth()->forgetGuards();
        $this->actingAs($user->fresh())->getJson('/api/admin/orders')->assertOk();
        $log = AuditLog::where('action', 'user.roles_changed')->sole();
        expect($log->old_values)->toBe(['roles' => ['customer']])->and($log->new_values)->toBe(['roles' => ['customer', 'staff']])
            ->and($log->user_id)->toBe($admin->id)->and($log->subject_id)->toBe((string) $user->id);
    });

    it('can take access away again', function () {
        $staff = withRole('staff');

        $this->actingAs(withRole('admin'))->putJson("/api/admin/users/{$staff->id}/roles", ['roles' => ['customer']])->assertOk();

        auth()->forgetGuards();
        $this->actingAs($staff->fresh())->getJson('/api/admin/orders')->assertForbidden();
    });

    it('does not write an audit entry when nothing changed', function () {
        $user = shopper();

        $this->actingAs(withRole('admin'))->putJson("/api/admin/users/{$user->id}/roles", ['roles' => ['customer']])->assertOk();

        expect(AuditLog::where('action', 'user.roles_changed')->count())->toBe(0);
    });

    it('refuses made-up roles, empty lists and non-admins', function () {
        $user = shopper();
        $admin = withRole('admin');

        $this->actingAs($admin)->putJson("/api/admin/users/{$user->id}/roles", ['roles' => ['wizard']])->assertUnprocessable();
        $this->actingAs($admin)->putJson("/api/admin/users/{$user->id}/roles", ['roles' => []])->assertUnprocessable();
        $this->actingAs($admin)->putJson("/api/admin/users/{$user->id}/roles", [])->assertUnprocessable();
        $this->actingAs(withRole('staff'))->putJson("/api/admin/users/{$user->id}/roles", ['roles' => ['admin']])->assertForbidden();
        $this->actingAs($user)->putJson("/api/admin/users/{$user->id}/roles", ['roles' => ['admin']])->assertForbidden();
        expect($user->fresh()->hasRole('admin'))->toBeFalse();
    });

    it('stops admins changing their own roles', function () {
        $admin = withRole('admin');
        $other = withRole('admin');

        $this->actingAs($admin)->putJson("/api/admin/users/{$admin->id}/roles", ['roles' => ['customer']])->assertUnprocessable()->assertJsonValidationErrors('roles');
        expect($admin->fresh()->hasRole('admin'))->toBeTrue();

        // another admin can, as long as one admin is left
        $this->actingAs($admin)->putJson("/api/admin/users/{$other->id}/roles", ['roles' => ['customer']])->assertOk();
    });

    it('never removes the last active admin', function () {
        $lastAdmin = withRole('admin');
        $inactiveAdmin = User::factory()->create(['is_active' => false]);
        $inactiveAdmin->assignRole('admin');
        $actor = withRole('admin');
        $this->actingAs($actor)->putJson("/api/admin/users/{$actor->id}/roles", ['roles' => ['customer']])->assertUnprocessable(); // self

        // two active admins ($lastAdmin, $actor): removing one is fine, removing the other then is refused
        $this->actingAs($actor)->putJson("/api/admin/users/{$lastAdmin->id}/roles", ['roles' => ['customer']])->assertOk();
        $lastAdmin->refresh();
        $this->actingAs($lastAdmin)->putJson("/api/admin/users/{$actor->id}/roles", ['roles' => ['customer']])->assertForbidden(); // no longer an admin
    });
});

describe('deactivating a user', function () {
    it('stops them signing in and ends the session they have open', function () {
        $user = shopper();
        $admin = withRole('admin');
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        DB::table('sessions')->insert(['id' => 'def', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        $this->actingAs($admin)->putJson("/api/admin/users/{$user->id}/status", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $admin->id)->count())->toBe(1); // nobody else is signed out
        expect(AuditLog::where('action', 'user.deactivated')->sole()->subject_id)->toBe((string) $user->id);

        // The password is right (see the next test, where the same login works once reactivated), yet they are refused.
        auth()->forgetGuards();
        $this->spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Password123'])->assertUnprocessable();
    });

    it('signs them out on their very next request, even though their session was open', function () {
        $user = shopper();
        $this->actingAs($user)->getJson('/api/cart')->assertOk();
        $user->forceFill(['is_active' => false])->save();
        auth()->forgetGuards(); // in real life every request starts fresh; a test's requests share one app

        // The user arrives the way a real one does: through the session ("web") guard.
        $this->actingAs($user->fresh(), 'web')->getJson('/api/cart')->assertUnauthorized();
    });

    it('can be undone', function () {
        $user = User::factory()->create(['is_active' => false]);
        $user->assignRole('customer');
        $admin = withRole('admin');

        $this->actingAs($admin)->putJson("/api/admin/users/{$user->id}/status", ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);

        expect(AuditLog::where('action', 'user.activated')->count())->toBe(1);
        auth()->forgetGuards();
        $this->spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Password123'])->assertOk();
    });

    it('cannot deactivate yourself, or the last active admin', function () {
        $admin = withRole('admin');

        $this->actingAs($admin)->putJson("/api/admin/users/{$admin->id}/status", ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $second = withRole('admin');
        $this->actingAs($admin)->putJson("/api/admin/users/{$second->id}/status", ['is_active' => false])->assertOk(); // one admin is left
        $second->refresh();
        $this->actingAs($admin)->putJson("/api/admin/users/{$second->id}/status", ['is_active' => false])->assertOk(); // already off: nothing to do

        expect($admin->fresh()->is_active)->toBeTrue();
    });

    it('needs the users.manage permission', function () {
        $user = shopper();

        $this->actingAs(withRole('staff'))->putJson("/api/admin/users/{$user->id}/status", ['is_active' => false])->assertForbidden();
        $this->actingAs($user)->putJson("/api/admin/users/{$user->id}/status", ['is_active' => false])->assertForbidden();
        expect($user->fresh()->is_active)->toBeTrue();
    });
});
