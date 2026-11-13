#!/bin/sh
set -e

# Cache config, route, dan view dibuat saat container mulai, karena nilai .env baru tersedia di sini, bukan saat build.
# Migration tidak dijalankan otomatis; jalankan sendiri setelah backup (lihat docs/docker.md).
php artisan optimize --quiet

exec "$@"
