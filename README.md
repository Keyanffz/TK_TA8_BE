# Backend TK Tarbiyathul Athfal 8

REST API Laravel 13 untuk sistem informasi TK Tarbiyathul Athfal 8 (token Sanctum, dokumentasi OpenAPI lewat Scramble).

- Spesifikasi dan kontrak API: `PROMPT_BE_TK.md`
- Instalasi, variabel `.env`, status fase, dan keputusan teknis: `dokumentasi.md`

Menjalankan singkat:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan dev
```

Dokumentasi API tersedia di `http://localhost:8000/docs/api` (selain production).
