<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Makes sure the schema the shop's tables live in exists, before the migrations run. Run on every start; safe to repeat.
 *
 * Almost everywhere that schema is "public", which always exists, and there is nothing to create. The exception is Supabase:
 * it publishes every table in "public" through a web API, so there the shop is given a schema of its own (DB_SCHEMA=shop), and
 * a brand-new database does not have it yet.
 *
 * It also REFUSES to go on (exit code 2, so the start-up script stops at once instead of retrying) when the tables would land
 * in a "public" schema that hands every new table to web-API roles: customers, orders and password hashes must never be
 * published by forgetting one setting.
 */
class PrepareDatabase extends Command
{
    protected $signature = 'shop:prepare-database';

    protected $description = "Create the schema the shop's tables live in (DB_SCHEMA), if it is not there yet";

    public function handle(): int
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return self::SUCCESS;
        }

        // The first name is where new tables go; any others (e.g. "shop,extensions") are only looked in.
        $schema = trim(explode(',', (string) $connection->getConfig('search_path'))[0], " \"'");

        if ($schema === '' || $schema === 'public') {
            if ($this->publicSchemaIsPublished($connection)) {
                $this->error('This database (Supabase) publishes every table in its "public" schema through a web API, and the shop is set to put its tables there. Set DB_SCHEMA=shop so customers, orders and passwords stay private. Nothing was created.');

                return self::INVALID;
            }

            return self::SUCCESS;
        }

        // The name goes into the statement itself (a schema name cannot be a bound value), so only a plain name is accepted.
        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $schema)) {
            $this->error("DB_SCHEMA is \"{$schema}\". Use a plain name: lowercase letters, numbers and _ only, for example \"shop\".");

            return self::INVALID;
        }

        $connection->statement("CREATE SCHEMA IF NOT EXISTS \"{$schema}\"");
        $this->line("The shop's tables live in the \"{$schema}\" schema.");

        return self::SUCCESS;
    }

    /**
     * True when a table this user creates in "public" is automatically handed to the roles behind a web API ("anon",
     * "authenticated"). That is how Supabase sets up every database; an ordinary PostgreSQL server has no such rule.
     */
    private function publicSchemaIsPublished(Connection $connection): bool
    {
        return (bool) $connection->selectOne(<<<'SQL'
            select exists (
                select 1
                from pg_default_acl d
                join pg_namespace n on n.oid = d.defaclnamespace
                cross join lateral aclexplode(d.defaclacl) a
                join pg_roles r on r.oid = a.grantee
                where n.nspname = 'public'
                  and d.defaclobjtype = 'r'
                  and pg_get_userbyid(d.defaclrole) = current_user
                  and r.rolname in ('anon', 'authenticated')
            ) as published
            SQL)->published;
    }
}
