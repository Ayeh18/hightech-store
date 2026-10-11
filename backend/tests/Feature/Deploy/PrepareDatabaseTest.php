<?php

use Illuminate\Support\Facades\DB;

/** Points the connection at another schema for one test (the tests themselves run in "public"). */
function useSchema(string $searchPath): void
{
    config(['database.connections.pgsql.search_path' => $searchPath]);
    DB::purge('pgsql');
}

function schemaExists(string $name): bool
{
    return DB::connection('pgsql')->selectOne('select exists (select 1 from information_schema.schemata where schema_name = ?) as found', [$name])->found;
}

afterEach(function () {
    DB::connection('pgsql')->statement('DROP SCHEMA IF EXISTS shop_install_check CASCADE');
    useSchema('public');
});

it('does nothing when the shop lives in the usual "public" schema', function () {
    $this->artisan('shop:prepare-database')->doesntExpectOutputToContain('schema')->assertSuccessful();
});

it('creates the shop\'s own schema when one is asked for, so the migrations have somewhere to put the tables', function () {
    useSchema('shop_install_check');
    expect(schemaExists('shop_install_check'))->toBeFalse();

    $this->artisan('shop:prepare-database')->expectsOutputToContain('live in the "shop_install_check" schema')->assertSuccessful();

    expect(schemaExists('shop_install_check'))->toBeTrue();
});

it('can be run on every start: an existing schema, and everything in it, is left alone', function () {
    useSchema('shop_install_check');
    $this->artisan('shop:prepare-database')->assertSuccessful();
    DB::connection('pgsql')->statement('CREATE TABLE shop_install_check.keepsake (id int)');

    $this->artisan('shop:prepare-database')->assertSuccessful();

    expect(DB::connection('pgsql')->selectOne("select count(*) as n from information_schema.tables where table_schema = 'shop_install_check'")->n)->toBe(1);
});

it('uses the first name when more are listed, since that is where new tables go', function () {
    useSchema('shop_install_check, extensions');

    $this->artisan('shop:prepare-database')->assertSuccessful();

    expect(schemaExists('shop_install_check'))->toBeTrue()->and(schemaExists('extensions'))->toBeFalse();
});

it('refuses a schema name that is not a plain name, and never runs it as SQL', function (string $name) {
    useSchema($name);

    $this->artisan('shop:prepare-database')->expectsOutputToContain('Use a plain name')->assertExitCode(2);

    expect(schemaExists('shop_install_check'))->toBeFalse();
})->with([
    'sql injection' => ['x"; CREATE SCHEMA shop_install_check; --'],
    'capital letters' => ['Shop'],
    'a dash' => ['my-shop'],
    'starts with a number' => ['1shop'],
]);

it('refuses to put the shop in a "public" schema that hands every new table to a web API (Supabase), and creates nothing', function () {
    // What Supabase sets up in every database. Both statements are undone with the test's transaction.
    DB::statement('CREATE ROLE anon NOLOGIN');
    DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO anon');

    $this->artisan('shop:prepare-database')
        ->expectsOutputToContain('Set DB_SCHEMA=shop so customers, orders and passwords stay private')
        ->assertExitCode(2);
});
