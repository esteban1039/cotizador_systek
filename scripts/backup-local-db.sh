#!/usr/bin/env bash
set -euo pipefail
umask 077
project_dir="$(cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$project_dir"
backup_dir="$project_dir/backend/storage/app/private/backups"
mkdir -p "$backup_dir"
backup_file="$(mktemp "$backup_dir/systek-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX")"
trap 'rm -f -- "$backup_file"' EXIT
# This command only reads the development database; it never runs migrations.
docker compose exec -T postgres pg_dump --username=systek --dbname=systek --format=custom > "$backup_file"
docker compose exec -T postgres pg_restore --list < "$backup_file" > /dev/null
trap - EXIT
printf 'Copia local verificada: %s\n' "$backup_file"
