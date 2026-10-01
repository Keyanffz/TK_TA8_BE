# Backend TK Tarbiyathul Athfal 8

REST API Laravel 13 untuk sistem informasi TK Tarbiyathul Athfal 8: akun Kepala Sekolah, guru, dan wali murid (token Sanctum), data murid dan kelas, tagihan dan pembayaran, kegiatan kelas, rapor, pengumuman, agenda, PPDB, dan konten landing page. Dokumentasi OpenAPI dibuat Scramble.

- Spesifikasi dan kontrak API: `PROMPT_BE_TK.md`
- Instalasi lengkap, variabel `.env`, status fase, keputusan teknis, dan akun demo: `dokumentasi.md`
- Spec OpenAPI untuk generate tipe di FE: `storage/api-docs/api.json`

Menjalankan di lokal (PHP 8.4+, MySQL 8 atau MariaDB):

```bash
composer install
cp .env.example .env          # isi DB_*, SUPERADMIN_*, MAIL_FROM_ADDRESS
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan db:seed --class=DemoSeeder   # opsional, data contoh
php artisan dev                # server :8000 + queue:listen + log
php artisan schedule:work      # scheduler tagihan dan absensi
```

Dokumentasi API: `http://localhost:8000/docs/api` (selain production).

Pengecekan sebelum commit:

```bash
php artisan test
./vendor/bin/pint --test
composer phpstan
composer check:slop
php artisan scramble:export --path=storage/api-docs/api.json
```

Di server produksi jalankan worker `php artisan queue:work` dan cron `* * * * * cd /path/ke/app && php artisan schedule:run`, dan isi `TRUSTED_PROXIES` kalau aplikasi berada di balik reverse proxy.
