<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Local development accounts only. DatabaseSeeder refuses to run this in production. */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['Admin User', 'admin@example.com', Role::Admin],
            ['Staff User', 'staff@example.com', Role::Staff],
            ['Customer User', 'customer@example.com', Role::Customer],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => 'Password123', 'email_verified_at' => now()],
            );
            $user->assignRole($role);
        }
    }
}
