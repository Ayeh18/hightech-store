#!/bin/sh
# Runs once, the first time the Postgres volume is created.
# The test suite uses its own database so it can never wipe development data.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    CREATE DATABASE ${POSTGRES_DB}_testing OWNER ${POSTGRES_USER};
SQL
