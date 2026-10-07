<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests hit a real PostgreSQL test database (pc_store_testing), rebuilt inside a
| transaction per test, and always start with the roles/permissions seeded.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(RolePermissionSeeder::class))
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
