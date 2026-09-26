# Dokumentasi Backend TK Tarbiyathul Athfal 8

REST API untuk sistem informasi TK Tarbiyathul Athfal 8. Dipakai oleh frontend Next.js dan nanti aplikasi Flutter. Acuan desain dan kontrak API: `PROMPT_BE_TK.md` (Bagian A sama persis dengan `PROMPT_FE_TK.md` di repo FE).

## Status

| Fase | Status |
|---|---|
| 0. Analisis | Selesai, rencana disetujui |
| 1. Fondasi | Selesai, menunggu konfirmasi |
| 2. Database | Belum |
| 3. Auth & akun | Belum |
| 4. Master akademik | Belum |
| 5. Keuangan | Belum |
| 6. Akademik & komunikasi | Belum |
| 7. PPDB, CMS, dashboard | Belum |
| 8. Hardening | Belum |

Endpoint yang sudah ada: `GET /api/v1/health`.

## Stack terpasang

| Komponen | Versi |
|---|---|
| PHP | 8.4 (minimum; dibutuhkan activitylog 5 dan Pest 5) |
| laravel/framework | 13.33.0 |
| laravel/sanctum | 4.3.3 |
| dedoc/scramble | 0.13.45 |
| google/apiclient | 2.20.1 (layanan Google dipangkas, hanya `Oauth2` disimpan) |
| spatie/laravel-query-builder | 7.3.5 |
| spatie/laravel-activitylog | 5.1.1 |
| barryvdh/laravel-dompdf | 3.1.2 |
| maatwebsite/excel | 4.0.3 |
| intervention/image | 4.3.3 |
| stevebauman/purify | 6.3.2 |
| pestphp/pest (+ plugin laravel) | 5.2.1 / 5.0.1 |
| larastan/larastan | 3.12.2 (level 6) |
| laravel/pint | 1.32.1 |

Database: MySQL 8 (utf8mb4). Test memakai SQLite in-memory (`phpunit.xml`).

## Instalasi dan menjalankan

Prasyarat: PHP 8.4 dengan ekstensi `pdo_mysql`, `mbstring`, `gd`, `zip`, `intl`, `dom`, `xml`, `fileinfo`; Composer 2; MySQL 8.

```bash
composer install
cp .env.example .env
php artisan key:generate
# buat database MySQL sesuai DB_DATABASE di .env
php artisan migrate
```

Menjalankan di lokal:

```bash
php artisan dev            # server (port 8000) + queue:listen + log (pail)
php artisan schedule:work  # scheduler; dibutuhkan mulai Fase 5 (tagihan otomatis)
```

Di server produksi:

- Worker queue: `php artisan queue:work` (notifikasi dan email lewat queue driver `database`).
- Scheduler: cron `* * * * * cd /path/ke/app && php artisan schedule:run >> /dev/null 2>&1`.

Composer yang dijalankan sebagai root (misalnya di container) menonaktifkan plugin; set `COMPOSER_ALLOW_SUPERUSER=1` supaya plugin Pest terpasang.

## Pengecekan di akhir setiap fase

```bash
php artisan test
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
composer check:slop
php artisan scramble:export --path=storage/api-docs/api.json
```

`composer check:slop` menjalankan `scripts/check-slop.sh` (Bagian C6): emoji di source/dokumentasi/pesan commit, sisa debug, placeholder, domain contoh di luar test, pembungkam checker, dan kata terlarang C3.

## Variabel `.env` penting

| Variabel | Keterangan |
|---|---|
| `APP_URL` | URL backend; dipakai sebagai server di dokumentasi OpenAPI |
| `APP_LOCALE`, `APP_FAKER_LOCALE` | `id`, `id_ID` |
| `DB_*` | Koneksi MySQL |
| `SESSION_DRIVER` | `file`; session hanya dipakai halaman `/docs/api` |
| `QUEUE_CONNECTION` | `database` |
| `CACHE_STORE` | `database` (rate limiter dan cache pengaturan) |
| `MAIL_MAILER`, `MAIL_FROM_ADDRESS` | `log` untuk lokal. `MAIL_FROM_ADDRESS` sengaja kosong di `.env.example`, wajib diisi sebelum email dipakai (Fase 3) |
| `FRONTEND_URL` | Satu-satunya origin yang diizinkan CORS |

Variabel yang akan ditambahkan saat dipakai: `GOOGLE_CLIENT_ID` (Fase 3), `SUPERADMIN_NAME`, `SUPERADMIN_EMAIL`, `SUPERADMIN_PASSWORD` (Fase 2).

## Dokumentasi API

- UI: `GET /docs/api` (Stoplight Elements), JSON: `GET /docs/api.json`. Hanya terbuka di environment selain `production` (Gate `viewApiDocs`).
- Spec hasil export dikomit di `storage/api-docs/api.json` untuk generate tipe TypeScript di FE. Server di spec: `{APP_URL}/api/v1`, path relatif terhadap prefix itu (misal `/health`).
- Route dengan middleware `auth:sanctum` otomatis bertanda Bearer; route lain `security: []`.

## Pola respons

- Sukses: `ApiResponse::success($data, $message, $meta, $status)`.
- List berpaginasi: `ApiResponse::paginated(XResource::collection($paginator))` menghasilkan `meta: { current_page, per_page, total, last_page }`.
- Error: `ApiResponse::error($message, KodeError::X, $status = null, $errors = null)`. Status bawaan diambil dari `KodeError::status()`.
- Pelanggaran aturan bisnis: lempar `App\Exceptions\BusinessRuleException` dengan pesan untuk pengguna, dirender 422 `BUSINESS_RULE`.

Pemetaan exception ke format A7 (`App\Exceptions\ApiExceptionRenderer`, didaftarkan di `bootstrap/app.php`):

| Sumber | Status | Kode |
|---|---|---|
| `ValidationException` | 422 | `VALIDATION_ERROR` (pesan "Data tidak valid", `errors` per field) |
| `AuthenticationException` | 401 | `UNAUTHENTICATED` |
| `BusinessRuleException` | 422 | `BUSINESS_RULE` |
| `AuthorizationException` / 403 | 403 | `FORBIDDEN` |
| `ModelNotFoundException`, route tidak ada, metode HTTP salah (405) | 404 | `NOT_FOUND` |
| Throttle | 429 | `TOO_MANY_REQUESTS` (header `Retry-After` ikut dikirim) |
| Unggahan melebihi batas server (413) dan status 4xx lain di luar daftar | 422 | `VALIDATION_ERROR` |
| Mode pemeliharaan | 503 | `SERVER_ERROR` |
| Exception lain | 500 | `SERVER_ERROR` (detail tidak dikirim ke klien, tetap tercatat di log) |

Middleware:

- `ForceJsonResponse`: dipasang di grup `api`, memaksa `Accept: application/json`.
- `akun.aktif` (`EnsureAccountActive`): token milik akun selain `aktif` ditolak 403 dengan `ACCOUNT_PENDING` / `ACCOUNT_REJECTED` / `ACCOUNT_INACTIVE`.
- `role:super_admin,guru` (`EnsureRole`): role di luar daftar ditolak 403 `FORBIDDEN`. Nama role yang salah ketik di route memicu error 500 supaya cepat ketahuan.

## Perubahan dari spesifikasi awal

Disetujui setelah Fase 0 dan sudah ditulis ke `PROMPT_BE_TK.md` (Bagian A dan B) serta Bagian A `PROMPT_FE_TK.md` (commit `18b77e5` di BE, `d02aa0e` di FE):

1. PHP minimum 8.4 (sebelumnya 8.3+), karena `spatie/laravel-activitylog` 5 dan Pest 5 membutuhkannya.
2. `intervention/image` v4 (sebelumnya v3), versi stabil terbaru.
3. Kepala Sekolah punya profil `guru` (jabatan "Kepala Sekolah") yang dibuat `SuperAdminSeeder`, supaya bisa mencatat kegiatan kelas (`kegiatan_kelas.guru_id` mengarah ke `guru`). Profil ini tidak muncul di `GET /guru`, tidak bisa dinonaktifkan, dan tidak dihitung di statistik `guru_aktif`.
4. Kunci pengaturan baru `ppdb.tahun_ajaran_id` sebagai tahun ajaran tujuan PPDB. `ppdb.dibuka = true` ditolak kalau kunci ini kosong atau tahun ajarannya tidak ada. Kuota dihitung per tahun ajaran tersebut.
5. Kolom baru `pendaftaran.hubungan` (enum Hubungan), diisi wali saat `POST /pendaftaran`, dipakai saat menautkan ketika diterima.
6. `ACCOUNT_REJECTED` saat login membawa alasan penolakan di `message`.
7. Payload dashboard guru mendapat `pembayaran_menunggu` (int untuk guru `bisa_kelola_keuangan`, `null` untuk guru lain).
8. `POST /guru` mengembalikan `password_awal` sekali di respons 201; tidak dikirim lewat email dan tidak disimpan sebagai teks biasa.
9. Field opsional `perangkat` (`web` | `mobile`, default `web`) di `POST /auth/login` dan `POST /auth/google`, dipakai sebagai nama token.
10. Field gambar di respons pengaturan mendapat pasangan `*_url` (`profil.logo_url`, `landing.hero.gambar_url`, `landing.fasilitas[].gambar_url`); diabaikan saat `PUT /pengaturan`.
11. `POST /tagihan` (tagihan sekali) melewati murid yang sudah punya tagihan jenis itu (selain `dibatalkan`) dan mengembalikan `{ dibuat, dilewati }`.

## Keputusan teknis

Disetujui di Fase 0 (belum semuanya dipakai; diterapkan di fase terkait):

- Signed URL media (`GET /media/{token}`): hak akses dicek saat URL dibuat di Resource; endpoint hanya memvalidasi signature, masa berlaku 30 menit, dan path terenkripsi, tanpa `auth:sanctum`, karena FE memakai URL itu langsung di `<img>`. Siapa pun yang memegang URL bisa membukanya selama 30 menit. Keputusan ini belum ditulis ke `PROMPT_BE_TK.md`.
- Kolom yang tidak diisi saat akun dibuat bersifat nullable: guru (nip, nuptk, tempat/tanggal lahir, alamat, pendidikan terakhir, foto; `jabatan` default "Guru"), wali murid (pekerjaan, alamat; diisi saat onboarding).
- Resource yang tidak dirinci A7 berisi kolom tabel tanpa password, token, `deleted_at`; `*_path` diganti `*_url`; relasi di-nest.
- Email hanya untuk persetujuan/penolakan guru dan reset password. Notifikasi lain lewat database.
- Link reset password: `{FRONTEND_URL}/reset-password?token=…&email=…`. Field `url` notifikasi mengikuti peta route FE B4.
- Wali tidak mengisi `jumlah` pembayaran; server mengisi `jumlah = total` tagihan (juga untuk tunai).
- Pembatalan tagihan ditolak kalau tagihan `lunas` atau masih ada pembayaran `menunggu`; alasan disimpan di `catatan` dan activity log.
- Keringanan untuk murid + jenis tagihan yang sama tidak boleh tumpang tindih periodenya; berlaku juga untuk tagihan sekali (acuan tanggal pembuatan).
- Generate tagihan bulanan hanya untuk periode dalam rentang tahun ajaran aktif. `keuangan.tanggal_jatuh_tempo` divalidasi 1–28.
- Kode `INV-YYYYMM` tagihan sekali memakai bulan pembuatan. NIS memakai tahun `tanggal_masuk`, nomor urut mulai lagi tiap tahun.
- Tautkan anak dibatasi 5 percobaan per menit per user. Wali pertama yang tertaut menjadi kontak utama.
- Login mengecek status akun hanya setelah password benar.
- `PATCH /guru/{id}/status` hanya untuk guru `aktif`/`nonaktif`; guru `pending` diproses lewat setujui/tolak.
- `GET /pengaturan` dan `GET /public/profil` mengembalikan objek datar dengan kunci lengkap (`"profil.visi": …`), sama seperti format `PUT /pengaturan`.
- Enum disimpan sebagai kolom `string` (bukan ENUM MySQL) dan di-cast ke enum PHP.
- Aturan yang tidak bisa dijaga index MySQL (tepat 1 tahun ajaran aktif, 1 kelas per tahun ajaran, maksimal 1 pembayaran `menunggu`/`diterima` per tagihan) dijaga di service dalam transaksi dengan lock baris.

Diambil selama Fase 1:

- Migration `users` (dan `password_reset_tokens` yang dipisah ke file sendiri) dikerjakan di Fase 1, bukan Fase 2, karena middleware `EnsureAccountActive` dan `EnsureRole` membaca kolom `role` dan `status`. Tabel `sessions` bawaan tidak dibuat.
- `ApiResponse::paginated()` menerima `XResource::collection($paginator)`, bukan `($paginator, ResourceClass)` seperti contoh di B3: dengan nama class sebagai string, Scramble mendokumentasikan `data` sebagai `string`; dengan koleksi, `data` terbaca sebagai array Resource.
- Kode error A7 dimodelkan sebagai enum `App\Enums\KodeError` beserta status HTTP-nya, dipakai bersama oleh `ApiExceptionRenderer`, middleware, dan extension Scramble.
- `App\Support\Scramble\ApiErrorResponseExtension` mengganti bentuk error bawaan Scramble (`{ message, errors }`) dengan format A7 di dokumentasi, supaya tipe yang digenerate FE sesuai respons sebenarnya. Respons 403 dari middleware (`ACCOUNT_*`, `FORBIDDEN` role) tidak terdeteksi otomatis oleh Scramble.
- Field `errors` selalu ada di respons error (`null` jika tidak ada detail per field).
- Serialisasi tanggal global lewat `Carbon::serializeUsing()` di `AppServiceProvider`: semua tanggal di JSON ditulis ISO 8601 dengan offset `+07:00`. Kolom tanggal murni (misal `tanggal_lahir`) tetap harus diformat `Y-m-d` di Resource.
- Pesan untuk pengguna memakai sapaan "Anda", mengikuti contoh di A7.
- `lang/id/validation.php` dibuat dengan `laravel-lang/common` 6.8.0 di proyek terpisah, lalu hanya file ini yang dikomit. Paket itu tidak dipasang di repo karena membutuhkan ekstensi `bcmath` lewat `dragon-code/support`. Pesan yang belum diterjemahkan atau keliru diperbaiki (misal `password.letters` sebelumnya berbunyi "karakter", sekarang "huruf"), dan daftar `attributes` bawaan dikosongkan untuk diisi nama field proyek ini per fase.
- Rute bawaan yang tidak ada di A7 dimatikan: rute web `/`, `/up`, `sanctum/csrf-cookie` (`sanctum.routes = false`), dan `storage/{path}` milik disk `local` (`serve = false`).
- `google/apiclient-services` dipangkas lewat skrip `Google\Task\Composer::cleanup` (hanya `Oauth2` disimpan). Tanpa daftar layanan, skrip tidak memangkas apa pun dan paket berukuran sekitar 370 MB. `verifyIdToken()` tidak membutuhkan kelas layanan.
- Larastan memakai `parseModelCastsMethod: true`; tanpa itu, cast di `casts()` diabaikan karena PHPDoc `array<string, string>` dan kolom enum terbaca sebagai `string`.
- `laravel/pao` (bawaan skeleton Laravel 13) dilepas karena tidak ada di desain. `CLAUDE.md`/`AGENTS.md` bawaan skeleton (instruksi Laravel Boost), `CHANGELOG.md`, workflow `.github` milik repo Laravel, dan aset npm/Vite tidak disalin.
- CORS: dengan satu origin yang diizinkan, header `Access-Control-Allow-Origin` selalu berisi `FRONTEND_URL`, sehingga browser di origin lain menolak respons.

## Akun seed

Belum ada. `SuperAdminSeeder` dan `DemoSeeder` dibuat di Fase 2.

## Changelog

### Fase 0

- `PROMPT_BE_TK.md`: spesifikasi proyek, lalu diperbarui dengan perubahan yang disetujui (lihat "Perubahan dari spesifikasi awal").
- `CLAUDE.md`: aturan kerja agent (baca spesifikasi dan dokumentasi, satu fase per sesi, patuhi Bagian C).

### Fase 1

File baru:

- `app/Support/ApiResponse.php`: helper format respons sukses, berpaginasi, dan error A7.
- `app/Enums/*.php`: 16 enum A5 dengan `label()`, `KodeError` (kode error A7 + status HTTP). `StatusAkun` punya `kodeErrorAkses()` dan `pesanAksesDitolak()` untuk middleware dan login.
- `app/Exceptions/BusinessRuleException.php`, `app/Exceptions/ApiExceptionRenderer.php`: penanganan semua exception API ke format A7.
- `app/Http/Middleware/ForceJsonResponse.php`, `EnsureAccountActive.php`, `EnsureRole.php`.
- `app/Http/Controllers/Api/V1/HealthController.php`, `routes/api.php`: `GET /api/v1/health`.
- `app/Support/Scramble/ApiErrorResponseExtension.php`: format error A7 di dokumentasi OpenAPI.
- `config/scramble.php`: `api_path = api/v1`, judul, `MiddlewareAuthSecurityStrategy` (Bearer), extension error.
- `config/cors.php`: hanya `FRONTEND_URL`, header Authorization diizinkan, tanpa credentials.
- `config/sanctum.php`: token berlaku 30 hari, rute csrf-cookie dimatikan.
- `database/migrations/0001_01_01_000003_create_password_reset_tokens_table.php` (dipisah dari migration users), `2026_09_26_072119_create_personal_access_tokens_table.php` (Sanctum).
- `lang/id/validation.php`: pesan validasi bahasa Indonesia.
- `storage/api-docs/api.json`: hasil export OpenAPI.
- `pint.json`, `phpstan.neon` (level 6), `scripts/check-slop.sh`.
- `tests/Pest.php` dan test: `HealthTest`, `FormatErrorTest`, `FormatSuksesTest`, `MiddlewareAksesTest`, `CorsTest`, `DokumentasiApiTest`, `SerialisasiTanggalTest`, `Unit/EnumKontrakTest`.
- `README.md`, `dokumentasi.md`.

File bawaan skeleton yang diubah:

- `bootstrap/app.php`: rute API dengan prefix `api/v1`, tanpa rute web dan `/up`; `ForceJsonResponse` di grup api; alias `akun.aktif` dan `role`; renderer exception API.
- `app/Providers/AppServiceProvider.php`: `preventLazyLoading` di non-production, serialisasi tanggal `+07:00`, Gate `viewApiDocs`.
- `app/Models/User.php`: kolom sesuai desain, cast enum `Role`/`StatusAkun`, `HasApiTokens`, `SoftDeletes`.
- `database/migrations/0001_01_01_000000_create_users_table.php`: kolom sesuai A4 (password nullable, google_id, role, status, no_hp, avatar_path, last_login_at, soft delete); `password_reset_tokens` dan `sessions` dikeluarkan.
- `database/factories/UserFactory.php`: data Indonesia, state `superAdmin()`, `waliMurid()`, `status()`.
- `database/seeders/DatabaseSeeder.php`: contoh "Test User" dihapus.
- `config/app.php`: timezone `Asia/Jakarta`, locale `id`, faker `id_ID`.
- `config/filesystems.php`: disk `local` tidak lagi melayani file lewat `storage/{path}`.
- `config/mail.php`: alamat pengirim bawaan `hello@example.com` dihapus.
- `.env.example`: MySQL, session `file`, queue/cache `database`, `FRONTEND_URL`.
- `composer.json`: paket B1, PHP ^8.4, skrip cleanup Google, `check:slop`; `laravel/pao` dan `phpunit/phpunit` langsung dilepas (PHPUnit 13 ikut lewat Pest).
- `tests/TestCase.php`; `tests/Feature/ExampleTest.php` dan `tests/Unit/ExampleTest.php` dihapus.
- `app/Http/Controllers/Controller.php`: komentar kosong dihapus.
