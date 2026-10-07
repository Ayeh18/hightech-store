#!/usr/bin/env bash
# One-time local setup: creates both .env files with a random DB password.
set -euo pipefail
cd "$(dirname "$0")/.."

if [[ -f .env || -f backend/.env ]]; then
  echo "A .env file already exists - leaving it alone. Delete both .env files to start over." >&2
  exit 1
fi

password="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"

cp .env.example .env
cp backend/.env.example backend/.env
sed -i "s/^UID=.*/UID=$(id -u)/; s/^GID=.*/GID=$(id -g)/; s/^DB_PASSWORD=.*/DB_PASSWORD=${password}/" .env
sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=${password}/" backend/.env

docker compose build app
docker compose run --rm app composer install --no-interaction
docker compose run --rm app php artisan key:generate
echo "Done. Next: docker compose up -d && docker compose exec app php artisan migrate --seed"
