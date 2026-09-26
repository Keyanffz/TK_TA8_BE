# Dokumentasi Backend TK Tarbiyathul Athfal 8

REST API untuk sistem informasi TK Tarbiyathul Athfal 8. Dipakai oleh frontend Next.js dan nanti aplikasi Flutter. Acuan desain dan kontrak API: `PROMPT_BE_TK.md` (Bagian A sama persis dengan `PROMPT_FE_TK.md` di repo FE).

## Status

| Fase | Status |
|---|---|
| 0. Analisis | Selesai, rencana disetujui |
| 1. Fondasi | Selesai |
| 2. Database | Selesai |
| 3. Auth & akun | Selesai |
| 4. Master akademik | Selesai (di laptop) |
| 5. Keuangan | Sedang dikerjakan |
| 6. Akademik & komunikasi | Belum |
| 7. PPDB, CMS, dashboard | Belum |
| 8. Hardening | Belum |

Endpoint yang sudah ada (prefix `/api/v1`):

| Kelompok | Endpoint |
|---|---|
| Umum | `GET /health`, `GET /media/{token}` (signed URL file private) |
| Auth publik | `POST /auth/login`, `POST /auth/google`, `POST /auth/register-guru`, `POST /auth/forgot-password`, `POST /auth/reset-password` |
| Auth (login) | `GET /auth/me`, `POST /auth/logout`, `PUT /auth/profil`, `PUT /auth/password` (SA, G) |
| Guru (SA) | `GET/POST /guru`, `GET/PUT /guru/{id}`, `POST /guru/{id}/setujui`, `POST /guru/{id}/tolak`, `PATCH /guru/{id}/status` |
| Wali murid (SA) | `GET /wali-murid`, `GET /wali-murid/{id}`, `PATCH /wali-murid/{id}/status` |
| Wali (W) | `PUT /wali/profil`, `POST /wali/tautkan-anak`, `GET /wali/anak` |
| Tahun ajaran | `GET /tahun-ajaran` (SA, G), `POST /tahun-ajaran`, `PUT/DELETE /tahun-ajaran/{id}`, `POST /tahun-ajaran/{id}/aktifkan` (SA) |
| Kelas | `GET /kelas`, `GET /kelas/{id}` (SA, G terbatas), `POST /kelas`, `PUT/DELETE /kelas/{id}`, `POST /kelas/{id}/murid`, `DELETE /kelas/{id}/murid/{murid_id}`, `POST /kelas/kenaikan` (SA) |
| Murid | `GET /murid`, `GET /murid/{id}` (SA, G terbatas, W anak sendiri), `POST /murid`, `PUT/DELETE /murid/{id}`, `POST /murid/{id}/kode-tautan`, `DELETE /murid/{id}/wali/{wali_murid_id}` (SA) |
| Tagihan (baca) | `GET /tagihan`, `GET /tagihan/{id}` (petugas keuangan semua, G murid kelasnya, W anak sendiri) |

## Keputusan menunggu review

Keputusan kecil yang diambil tanpa menunggu konfirmasi karena tidak mengubah kontrak A7 atau skema A4. Mohon ditinjau; yang tidak disetujui akan diubah.

Fase 4:

1. `GET /tagihan` dan `GET /tagihan/{id}` (baca saja, dengan scope B4 dan Policy) dibuat di Fase 4, bukan Fase 5, karena Policy dan scope diminta berlaku untuk semua endpoint murid, kelas, dan tagihan di Fase 4. Detail tagihan sudah berisi riwayat pembayaran dan `rekening` sekolah dari `keuangan.rekening`.
2. Tahun ajaran pertama yang dibuat langsung aktif; tahun ajaran berikutnya dibuat tidak aktif. `is_aktif` tidak diterima di `POST`/`PUT /tahun-ajaran`; satu-satunya jalan mengubahnya `POST /tahun-ajaran/{id}/aktifkan`. `semester_aktif` opsional (bawaan 1).
3. `DELETE /tahun-ajaran/{id}` ditolak `BUSINESS_RULE` kalau tahun ajaran sedang aktif, sudah punya kelas, tagihan, jenis tagihan, atau pendaftar PPDB, atau dipakai di `ppdb.tahun_ajaran_id`.
4. Kapasitas kelas dan `jumlah_murid` hanya menghitung penempatan berstatus `aktif`. `PUT /kelas/{id}` menolak kapasitas di bawah jumlah itu, dan menolak mengganti tahun ajaran kalau kelas sudah berisi murid. `DELETE /kelas/{id}` ditolak kalau kelas sudah punya murid, kegiatan, atau rapor. Wali kelas dan guru pendamping harus guru berakun aktif (profil guru Kepala Sekolah boleh) dan tidak boleh orang yang sama.
5. `DELETE /kelas/{id}/murid/{murid_id}` menghapus baris penempatan (untuk memperbaiki salah penempatan), bukan memberi status `keluar`. Murid yang keluar sekolah diubah lewat `PUT /murid/{id}`.
6. Kenaikan kelas: tahun ajaran asal = tahun ajaran aktif, tujuan harus berbeda. Setiap murid harus aktif dan punya penempatan `aktif` di tahun ajaran asal, dan belum punya kelas di tahun ajaran tujuan. Tingkat kelas tujuan tidak dicek (naik dari A ke B tidak dipaksa). Murid `lulus` mendapat `status = lulus` dan `tanggal_keluar` = `tanggal_selesai` tahun ajaran asal. Respons `{ naik, tinggal, lulus }` (jumlah per status). Tidak dicatat di activity log karena tidak ada di daftar B7.
7. `POST /murid` tidak menerima `status` (selalu `aktif`). `PUT /murid/{id}` mewajibkan `status`; `tanggal_keluar` wajib untuk `lulus`/`pindah`/`keluar` dan dikosongkan untuk `aktif`. Perubahan status ikut mengubah penempatan di tahun ajaran aktif: `lulus` → `lulus`, `pindah`/`keluar` → `keluar`, kembali `aktif` → penempatan dibuka lagi (dengan cek kapasitas). NIS tidak bisa diubah.
8. `DELETE /murid/{id}` hanya untuk data salah input: ditolak kalau murid sudah punya tagihan, rapor, atau data PPDB. Murid di-soft delete, penempatannya dihapus, dan kode tautannya dikosongkan.
9. Isi `MuridResource` sama untuk semua yang boleh melihat murid (Kepala Sekolah, guru pengampu, wali anak itu), termasuk NIK, alamat, dan `catatan_khusus`. Detail murid berisi `wali[]` (`id, nama, email, no_hp, hubungan, is_kontak_utama, tertaut_at`), sehingga ayah dan ibu saling melihat kontak masing-masing. `kode_tautan` dan `kode_tautan_expired_at` hanya untuk Kepala Sekolah.
10. Kode tautan hanya dibuat untuk murid aktif; kode baru menggantikan kode lama. `kode-tautan:bersihkan` dijadwalkan harian pukul 01:00 WIB (B6.2 tidak menyebut jam).
11. Melepas wali yang menjadi kontak utama memindahkan kontak utama ke wali yang paling awal tertaut. Wali yang dilepas tidak diberi notifikasi (tidak ada jenis notifikasi untuk itu di A7).
12. `bukti_url` pembayaran hanya diisi untuk petugas keuangan dan wali murid; guru tanpa izin keuangan melihat riwayat pembayaran murid kelasnya dengan `bukti_url: null`.
13. Parameter daftar di luar yang disebut A7: `GET /murid` `sort=nama|nis|created_at`; `GET /kelas` `sort=nama|created_at`; `GET /tahun-ajaran` `sort=nama|tanggal_mulai`; `GET /tagihan` `search` (kode tagihan, nama murid) dan `sort=jatuh_tempo|periode|created_at`. Semua daftar berpaginasi (bawaan 15, maksimal 100).
14. Di OpenAPI, `GET /murid/{id}` dan `GET /tagihan/{id}` masih mencantumkan 403 `FORBIDDEN` karena Scramble membaca pemanggilan `Gate::authorize`. Pada kenyataannya Policy membalas 404 `NOT_FOUND` untuk data di luar jangkauan.

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

Database: MySQL 8 (utf8mb4) di produksi. Migration, rollback, dan seeder sudah dijalankan di MySQL 8.0.46, MariaDB 10.11.14, dan MariaDB 12.3.3 (driver `mariadb`). Test memakai SQLite in-memory (`phpunit.xml`); seluruh test juga lulus saat dijalankan ke MySQL 8.0.46 dan MariaDB 10.11.14 (sampai Fase 3) dan ke MariaDB 12.3.3 (mulai Fase 4).

PHP: dikembangkan di 8.4.19 (Fase 0–3, container) dan 8.5.10 (mulai Fase 4, laptop). Test dijalankan dengan `--display-deprecations` di PHP 8.5.10 tanpa deprecation.

## Serah terima ke lingkungan lokal

Fase 0–3 dikerjakan di container cloud (Ubuntu 24.04, PHP 8.4.19, MySQL 8.0.46, dijalankan sebagai root). Mulai Fase 4 pengerjaan pindah ke laptop (Arch Linux, MariaDB). Sebelum serah terima, migration, rollback, `DatabaseSeeder`, `DemoSeeder`, dan seluruh test sudah dijalankan ke MariaDB 10.11.14 dengan driver `mariadb` dan lulus. MariaDB 11.x (versi di repo Arch) belum dicoba.

### Hasil di laptop (awal Fase 4)

Laptop: Arch Linux, PHP 8.5.10, Composer 2.9.2, MariaDB 12.3.3 (server `utf8mb4_unicode_ci`).

- `composer install` sempat gagal dua kali karena unduhan `google/apiclient-services` (±50 MB) dari codeload.github.com timeout di 300 detik. Berhasil setelah diulang dengan batas waktu lebih panjang: `COMPOSER_PROCESS_TIMEOUT=3600 php -d default_socket_timeout=3600 $(command -v composer) install`. Kalau ekstraksi gagal dengan "cannot find or open ... tmp-*.zip", hapus `vendor/composer/tmp-*` lalu ulangi. Ini masalah jaringan, bukan repo.
- `php artisan key:generate`, `migrate:fresh --seed`, `storage:link`, dan `DemoSeeder` lancar di MariaDB 12.3.3.
- 190 test lulus di SQLite dan di MariaDB 12.3.3, tanpa deprecation PHP 8.5; Pint, PHPStan, dan `check:slop` tanpa temuan. Tidak ada perbaikan yang dibutuhkan karena perbedaan versi.
- Ekstensi `exif` belum aktif di laptop (`php -m`). Unggahan tetap berhasil, tetapi foto dari HP bisa tersimpan miring (lihat tabel di bawah).
- Tidak ada database test terpisah. Test ke MariaDB dijalankan ke database utama `TK_TA8` (`DB_CONNECTION=mariadb DB_DATABASE=TK_TA8 php artisan test`), lalu database diisi ulang dengan `php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder` karena `RefreshDatabase` mengosongkannya.

### Ekstensi PHP

PHP 8.4 atau lebih baru. `composer check-platform-reqs` menampilkan ekstensi yang diminta paket di `composer.lock`: `dom`, `fileinfo`, `gd`, `iconv`, `libxml`, `openssl`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`, ditambah `mbstring` dan `ctype` (ada polyfill, tetapi versi native lebih cepat). Ekstensi berikut dipakai saat aplikasi berjalan tetapi tidak dicek Composer:

| Ekstensi | Dipakai untuk |
|---|---|
| `pdo_mysql` | koneksi MariaDB/MySQL |
| `pdo_sqlite` | test (SQLite in-memory di `phpunit.xml`) |
| `gd` dengan dukungan JPEG, PNG, WebP | memproses gambar unggahan (intervention/image driver GD) |
| `exif` | memutar foto dari HP sesuai orientasi EXIF. Tanpa ekstensi ini unggahan tetap berhasil, tetapi foto bisa tersimpan miring |
| `pcntl` | `php artisan pail`, bagian dari `php artisan dev` |
| `curl` | disarankan; Guzzle memakainya untuk mengambil sertifikat Google saat verifikasi ID token |

Di Arch, cek dengan `php -m`; ekstensi yang belum muncul diaktifkan lewat baris `extension=` di `/etc/php/php.ini`.

### Variabel `.env` yang wajib diisi

Mulai dari `cp .env.example .env`, lalu isi:

| Variabel | Nilai di laptop |
|---|---|
| `APP_KEY` | diisi `php artisan key:generate` |
| `APP_URL` | `http://localhost:8000`, sama dengan alamat `php artisan serve`, karena URL file publik (`avatar_url`, `foto_url` guru) dibentuk dari sini |
| `DB_CONNECTION` | `mariadb`. `.env.example` berisi `mysql` karena produksi memakai MySQL 8 |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | sesuai database MariaDB lokal |
| `SUPERADMIN_NAME`, `SUPERADMIN_EMAIL`, `SUPERADMIN_PASSWORD` | akun Kepala Sekolah; `SuperAdminSeeder` berhenti kalau kosong atau password kurang dari 8 karakter huruf dan angka |
| `MAIL_FROM_ADDRESS` | alamat pengirim apa saja, misalnya `tu@tkta8.test`. Walau `MAIL_MAILER=log`, email tanpa alamat pengirim gagal dengan "An email must have a "From" or a "Sender" header" dan job-nya masuk `failed_jobs` |

Boleh dibiarkan seperti di `.env.example`: `FRONTEND_URL=http://localhost:3000` (alamat `next dev`), `MAIL_MAILER=log`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=file`. `GOOGLE_CLIENT_ID` hanya perlu diisi untuk mencoba login Google; kalau kosong, `POST /auth/google` membalas 503.

### Dari `composer install` sampai test lulus

Database MariaDB kosong sudah dibuat dan `.env` sudah diisi.

```bash
composer install
cp .env.example .env
php artisan key:generate
# isi .env sesuai tabel di atas
php artisan migrate --seed
php artisan storage:link
php artisan db:seed --class=DemoSeeder   # opsional: data contoh dan akun demo
php artisan test
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
composer check:slop
```

Hasil saat serah terima: 190 test lulus; Pint, PHPStan, dan `check:slop` tanpa temuan.

- Test memakai SQLite in-memory dari `phpunit.xml`, jadi tidak menyentuh database lokal. Untuk menjalankan test ke MariaDB, buat database terpisah lalu timpa lewat variabel environment, misalnya `DB_CONNECTION=mariadb DB_DATABASE=tk_test php artisan test`; variabel environment shell mengalahkan nilai di `phpunit.xml`. Jangan arahkan ke database utama, karena `RefreshDatabase` mengosongkannya.
- Kalau `php artisan config:cache` pernah dijalankan, test ikut membaca konfigurasi yang di-cache. Jalankan `php artisan config:clear`, atau pakai `composer test` yang membersihkannya lebih dulu.

### Queue dan scheduler

- `php artisan dev` menjalankan server di port 8000, `queue:listen --tries=1`, dan `pail` (butuh `pcntl`). Tanpa `pcntl`, jalankan `php artisan serve` dan `php artisan queue:listen --tries=1` di dua terminal.
- Semua notifikasi, baik database maupun email, lewat queue `database`. Tanpa worker, job menunggu di tabel `jobs`: notifikasi belum masuk tabel `notifications` dan email belum ditulis. Untuk memproses antrean sekali lalu berhenti: `php artisan queue:work --stop-when-empty`. `queue:work` yang dibiarkan jalan harus di-restart setelah kode berubah; `queue:listen` tidak.
- Dengan `MAIL_MAILER=log`, email ditulis ke `storage/logs/laravel.log`, termasuk tautan reset password.
- Jadwal scheduler ada di `routes/console.php` (`php artisan schedule:list`). Di lokal jalankan `php artisan schedule:work` di terminal terpisah; di server produksi memakai cron (lihat "Instalasi dan menjalankan").

### Yang khusus container dan tidak berlaku di laptop

- Container berjalan sebagai root, jadi Composer dijalankan dengan `COMPOSER_ALLOW_SUPERUSER=1` supaya plugin Pest aktif. Di laptop jalankan Composer sebagai user biasa tanpa variabel itu.
- Database container dinyalakan dengan `service mysql start` (MySQL 8.0.46, lalu diganti MariaDB 10.11.14 untuk verifikasi serah terima), dengan user `tk` / `tkLokal2026`. Kredensial ini hanya ada di `.env` container yang tidak di-commit; di laptop pakai kredensial MariaDB sendiri.
- Jaringan container lewat proxy dengan CA bundle sendiri, dan unduhan zip dari api.github.com diblokir. Karena itu `phpstan/phpstan` dipasang dari clone git dan `lang/id/validation.php` dibuat dengan `laravel-lang` di proyek terpisah. Di laptop `composer install` biasa sudah cukup: `composer.json` tidak berisi repository atau path khusus, dan `lang/id/validation.php` sudah di-commit.
- PHP container sudah memuat hampir semua ekstensi (termasuk `exif`, `pcntl`, `pdo_sqlite`). Di Arch cek satu per satu dengan `php -m`.
- `.env`, `vendor/`, symlink `public/storage`, gambar demo di `storage/app/...`, dan log tidak ikut repo. Semuanya dibuat ulang lewat urutan perintah di atas; gambar demo ditulis ulang oleh `DemoSeeder`.
- Batas unggahan PHP bawaan (`upload_max_filesize=2M`, `post_max_size=8M`) sama di container dan di Arch, dan juga berlaku untuk `php artisan serve`. Untuk mencoba unggahan sampai 5 MB lewat server lokal, naikkan ke `5M` dan `55M` di `php.ini`. Test tidak terpengaruh karena memakai file palsu.
- Pekerjaan di container memakai branch `claude/project-spec-setup-1vjwxm` yang di-merge ke `main` lewat PR per fase. Di laptop mulai dari `main` terbaru setelah PR Fase 3 di-merge.

## Instalasi dan menjalankan

Prasyarat: PHP 8.4 dengan ekstensi di "Serah terima ke lingkungan lokal", Composer 2, MySQL 8 atau MariaDB.

```bash
composer install
cp .env.example .env
php artisan key:generate
# buat database MySQL sesuai DB_DATABASE, lalu isi SUPERADMIN_NAME/EMAIL/PASSWORD di .env
php artisan migrate --seed
php artisan storage:link
```

`migrate --seed` menjalankan `DatabaseSeeder`: akun Kepala Sekolah + profil gurunya, tiga elemen penilaian, dan 24 kunci pengaturan. Seeder ini aman dijalankan ulang (data yang sudah ada tidak ditimpa, termasuk password Kepala Sekolah).

Data contoh untuk pengembangan lokal (ditolak di production dan di database yang sudah berisi tahun ajaran):

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSeeder
```

Gambar placeholder demo ditulis ke `storage/app/private/{kegiatan,bukti-bayar,ppdb,murid}` dan `storage/app/public/galeri` (di-ignore git). `migrate:fresh` tidak menghapus file itu; hapus foldernya kalau ingin bersih.

Menjalankan di lokal:

```bash
php artisan dev            # server (port 8000) + queue:listen + log (pail)
php artisan schedule:work  # scheduler; dibutuhkan mulai Fase 5 (tagihan otomatis)
```

Di server produksi:

- Worker queue: `php artisan queue:work` (notifikasi dan email lewat queue driver `database`).
- Scheduler: cron `* * * * * cd /path/ke/app && php artisan schedule:run >> /dev/null 2>&1`.
- `php.ini`: `upload_max_filesize` minimal `5M` (batas per file di B5) dan `post_max_size` cukup untuk unggahan terbanyak dalam satu request (kegiatan: 10 foto, jadi minimal `55M`). Request yang melewati `post_max_size` dibalas 422 `VALIDATION_ERROR` dengan pesan "Ukuran file terlalu besar. Maksimal 5 MB per file.".

Composer yang dijalankan sebagai root (misalnya di container) menonaktifkan plugin; set `COMPOSER_ALLOW_SUPERUSER=1` supaya plugin Pest terpasang.

## Pengecekan di akhir setiap fase

```bash
php artisan test
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
composer check:slop
php artisan scramble:export --path=storage/api-docs/api.json
DB_CONNECTION=mariadb DB_DATABASE=TK_TA8 php artisan test   # lalu isi ulang: migrate:fresh --seed + DemoSeeder
```

`composer check:slop` menjalankan `scripts/check-slop.sh` (Bagian C6): emoji di source/dokumentasi/pesan commit, sisa debug, placeholder, domain contoh di luar test, pembungkam checker, dan kata terlarang C3.

## Variabel `.env` penting

| Variabel | Keterangan |
|---|---|
| `APP_URL` | URL backend; dipakai sebagai server di dokumentasi OpenAPI |
| `APP_LOCALE`, `APP_FAKER_LOCALE` | `id`, `id_ID` |
| `DB_*` | Koneksi MySQL (`DB_CONNECTION=mysql`) atau MariaDB (`DB_CONNECTION=mariadb`) |
| `SESSION_DRIVER` | `file`; session hanya dipakai halaman `/docs/api` |
| `QUEUE_CONNECTION` | `database` |
| `CACHE_STORE` | `database` (rate limiter dan cache pengaturan) |
| `MAIL_MAILER`, `MAIL_FROM_ADDRESS` | `MAIL_MAILER=log` di `.env.example` untuk development (email ditulis ke `storage/logs/laravel.log`). `MAIL_FROM_ADDRESS` sengaja kosong di `.env.example` dan wajib diisi, juga dengan mailer `log`, karena email persetujuan/penolakan guru dan reset password dikirim sejak Fase 3 |
| `FRONTEND_URL` | Satu-satunya origin yang diizinkan CORS; juga dasar tautan di email (`/login`, `/reset-password`) |
| `GOOGLE_CLIENT_ID` | Client ID OAuth Google Identity Services (sama dengan yang dipakai FE). Wajib untuk `POST /auth/google`; kalau kosong, endpoint itu membalas 503 `SERVER_ERROR` "Login Google belum dikonfigurasi. Hubungi pihak sekolah." dan penyebabnya tercatat di log |
| `SUPERADMIN_NAME`, `SUPERADMIN_EMAIL`, `SUPERADMIN_PASSWORD` | Akun Kepala Sekolah untuk `SuperAdminSeeder` (lewat `config/superadmin.php`). Password minimal 8 karakter berisi huruf dan angka; seeder berhenti dengan pesan jelas kalau kosong atau tidak valid |

`APP_URL` harus sama dengan alamat yang dibuka klien untuk URL file publik (`avatar_url`, `foto_url` guru), karena URL disk `public` dibentuk dari `APP_URL`. Signed URL file private dibentuk dari host request, jadi di balik reverse proxy server harus mempercayai header `X-Forwarded-*` (diatur di Fase 8).

## Dokumentasi API

- UI: `GET /docs/api` (Stoplight Elements), JSON: `GET /docs/api.json`. Hanya terbuka di environment selain `production` (Gate `viewApiDocs`).
- Spec hasil export dikomit di `storage/api-docs/api.json` untuk generate tipe TypeScript di FE. Server di spec: `{APP_URL}/api/v1`, path relatif terhadap prefix itu (misal `/health`).
- Route dengan middleware `auth:sanctum` otomatis bertanda Bearer; route lain `security: []`.
- Respons error ditulis inline per operasi dengan skema A7 dan `code` berupa enum. `ApiErrorResponseExtension` memetakan exception yang terdeteksi Scramble (termasuk `@throws` di service) ke kode A7. `ResponsErrorRouteExtension` menambahkan respons dari middleware yang tidak terdeteksi otomatis: 403 `FORBIDDEN` (`role:`, `signed`), 403 `ACCOUNT_PENDING`/`ACCOUNT_REJECTED`/`ACCOUNT_INACTIVE` (`akun.aktif`), 404 `NOT_FOUND` (route berparameter), 429 `TOO_MANY_REQUESTS` (`throttle:`). Beberapa kode pada status yang sama digabung dalam satu enum, misal 422 `BUSINESS_RULE` + `VALIDATION_ERROR`.

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
| `AksesAkunDitolakException` (login dengan akun belum/tidak aktif) | 403 | `ACCOUNT_PENDING` / `ACCOUNT_REJECTED` / `ACCOUNT_INACTIVE` |
| `LayananBelumDikonfigurasiException` (misalnya `GOOGLE_CLIENT_ID` kosong) | 503 | `SERVER_ERROR` dengan pesan untuk pengguna dari exception; penyebab teknis tercatat di log |
| `InvalidSignatureException` (signed URL media kedaluwarsa atau diubah) | 403 | `FORBIDDEN` ("Tautan file sudah kedaluwarsa atau tidak valid. …") |
| `ModelNotFoundException` / `abort(404)` di route yang ada | 404 | `NOT_FOUND` ("Data tidak ditemukan.") |
| Route tidak ada, metode HTTP salah (405) | 404 | `NOT_FOUND` ("Endpoint tidak ditemukan. …") |
| `InvalidQuery` spatie (filter/sort di luar daftar) | 422 | `VALIDATION_ERROR` |
| Throttle | 429 | `TOO_MANY_REQUESTS` (header `Retry-After` ikut dikirim) |
| Unggahan melebihi `post_max_size` (413) | 422 | `VALIDATION_ERROR` ("Ukuran file terlalu besar. Maksimal 5 MB per file.") |
| Status 4xx lain di luar daftar A7 | 422 | `VALIDATION_ERROR` |
| Mode pemeliharaan | 503 | `SERVER_ERROR` |
| Exception lain | 500 | `SERVER_ERROR` (detail tidak dikirim ke klien, tetap tercatat di log) |

Middleware:

- `ForceJsonResponse`: dipasang di grup `api`, memaksa `Accept: application/json`.
- `akun.aktif` (`EnsureAccountActive`): token milik akun selain `aktif` ditolak 403 dengan `ACCOUNT_PENDING` / `ACCOUNT_REJECTED` / `ACCOUNT_INACTIVE`.
- `role:super_admin,guru` (`EnsureRole`): role di luar daftar ditolak 403 `FORBIDDEN`. Nama role yang salah ketik di route memicu error 500 supaya cepat ketahuan.
- Policy (`app/Policies`, ditemukan otomatis dari nama model): `KelasPolicy`, `MuridPolicy`, `TagihanPolicy` memeriksa per data dengan scope yang sama seperti daftar (`Kelas::diampuOleh`, `Murid::visibleTo`, `Tagihan::visibleTo`) dan menolak dengan `Response::denyAsNotFound()`. Laravel mengubah penolakan itu menjadi `HttpException` 404 sebelum `ApiExceptionRenderer`, sehingga balasannya 404 `NOT_FOUND` "Data tidak ditemukan.", sama persis dengan id yang memang tidak ada. Controller memanggil `Gate::authorize('view', $model)` setelah `findOrFail`.
- `signed:relative`: hanya di `GET /media/{token}`.
- Rate limiter (`AppServiceProvider`): `login` 5/menit per email + IP, `login-google` 10/menit per IP, `tautkan-anak` 5/menit per user. Limiter API umum 120/menit dipasang di Fase 8.

## Auth dan akun

- Token Sanctum dikirim sebagai `Authorization: Bearer`, berlaku 30 hari, nama token = `perangkat` (`web` | `mobile`). Logout mencabut token yang sedang dipakai; ganti password mencabut token lain; reset password dan penonaktifan akun mencabut semua token.
- Login email hanya untuk Kepala Sekolah dan guru. Email tidak terdaftar, password salah, dan akun wali murid mendapat pesan yang sama ("Email atau password salah."). Status akun baru dicek setelah password benar.
- Login Google (`GoogleLoginService`): ID token diverifikasi `GoogleIdTokenVerifier` (tanda tangan, `aud` = `GOOGLE_CLIENT_ID`, masa berlaku) dan email harus terverifikasi. Akun dicari lewat `google_id` lalu email; email milik guru/Kepala Sekolah ditolak `BUSINESS_RULE`. Email baru dibuatkan akun wali murid aktif dengan `profil_lengkap = false` dan `is_new = true`. Kalau `GOOGLE_CLIENT_ID` kosong, verifier melempar `LayananBelumDikonfigurasiException` (503). Di test, verifier diganti mock.
- Lupa password tidak membedakan email terdaftar atau tidak, dan tidak mengirim apa pun ke akun wali murid (tidak punya password). Tautan berlaku 60 menit (`auth.passwords.users.expire`).
- `PUT /auth/profil` dan `PUT /guru/{id}` menerima `multipart/form-data` dengan metode PUT langsung (tanpa `_method`): PHP 8.4 mem-parse body PUT lewat `request_parse_body()` di Symfony HttpFoundation. Sudah dicoba dengan curl ke server lokal.
- Password baru (registrasi, ganti, reset): minimal 8 karakter berisi huruf dan angka (`Password::defaults()`). Nomor HP: diawali `08`, 10–15 digit (`App\Rules\NomorHp`).
- Gate `kelola-keuangan` memakai `User::bisaKelolaKeuangan()`; dipakai endpoint keuangan mulai Fase 5. Pembatasan per role lewat middleware `role:`.

## File dan media

`App\Services\MediaService` (B5):

- Gambar (`MediaService::aturanGambar()`: jpg/jpeg/png/webp, maksimal 5 MB) diperkecil ke lebar maksimal 1600 px, diputar sesuai EXIF, disimpan sebagai JPEG kualitas 80 dengan nama UUID, metadata EXIF dibuang.
- File publik (avatar, foto guru) di disk `public`, URL lewat `Storage::url()`. File lama dihapus setelah transaksi berhasil.
- File private di disk `local` disajikan lewat `GET /media/{token}`: token = path terenkripsi (`Crypt`, base64 url-safe), URL ditandatangani relatif (`URL::temporarySignedRoute(..., absolute: false)`) dan berlaku 30 menit, respons `Cache-Control: private, max-age=1800`. Token rusak atau file sudah dihapus dibalas 404.

## Skema database

- 35 tabel: 26 tabel domain A4 (termasuk `users`), bawaan Laravel (`cache`, `cache_locks`, `jobs`, `failed_jobs`, `password_reset_tokens`, `personal_access_tokens`, `notifications`, `migrations`), dan `activity_log` (spatie). Satu migration per tabel; `job_batches` dan `sessions` bawaan tidak dibuat karena tidak dipakai.
- Foreign key: tabel anak dan pivot `cascade` (`murid_wali`, `kelas_murid`, `pengumuman_kelas`, `pengumuman_murid`, `kegiatan_foto`, `rapor_detail`, `pendaftaran_dokumen`, `galeri_foto`, profil `guru`/`wali_murid` ke `users`); kolom pelaku (`dibuat_oleh`, `diverifikasi_oleh`, `disetujui_oleh`, `diproses_oleh`, `dibayar_oleh`) `null on delete`; `kelas.wali_kelas_id`, `kelas.guru_pendamping_id`, `pendaftaran.murid_id` `null on delete`; sisanya (data keuangan, rapor, kegiatan, tahun ajaran) `restrict`. `users`, `murid`, dan `pengumuman` memakai soft delete, jadi hapus fisik jarang terjadi.
- Cascade di database tidak menjalankan observer Eloquent. Penghapusan data yang punya file (kegiatan, rapor, PPDB, galeri) harus lewat Eloquent di service supaya file fisiknya ikut terhapus (B5).
- Scope visibilitas (B4), dipakai semua endpoint terkait mulai Fase 3:

| Scope | Kepala Sekolah | Guru | Wali murid |
|---|---|---|---|
| `Kelas::diampuOleh($user)` | – | kelas di tahun ajaran aktif tempat dia wali kelas atau guru pendamping | – |
| `GET /kelas`, `GET /kelas/{id}` | semua kelas | `Kelas::diampuOleh` | ditolak 403 (`role:`) |
| `Murid::visibleTo` | semua | murid di kelas yang diampu | anaknya |
| `Tagihan::visibleTo` | semua | petugas keuangan: semua; lainnya: murid yang terlihat | tagihan anaknya |
| `Pembayaran::visibleTo` | semua | petugas keuangan: semua; lainnya: pembayaran dari tagihan yang terlihat (riwayat di detail tagihan) | pembayaran tagihan anaknya |
| `KegiatanKelas::visibleTo` | semua | kelas yang diampu | kelas yang pernah atau sedang diikuti anaknya |
| `Rapor::visibleTo` | semua | kelas yang diampu | rapor anaknya berstatus `terbit` |
| `Pengumuman::visibleTo` (feed) | semua, termasuk draft | terbit untuk semua / guru / kelas yang diampu / murid di kelasnya, plus tulisannya sendiri (termasuk draft) | terbit untuk semua / wali murid / kelas anaknya / anaknya, plus tulisannya sendiri |
| `Pendaftaran::visibleTo` | semua | tidak ada | miliknya |

`User::bisaKelolaKeuangan()` menentukan petugas keuangan (Kepala Sekolah, atau guru dengan `bisa_kelola_keuangan`).

## Perubahan dari spesifikasi awal

Semua sudah ditulis ke `PROMPT_BE_TK.md` (Bagian A dan B) dan Bagian A `PROMPT_FE_TK.md`, sehingga Bagian A kedua file tetap identik. Nomor 1–11 disetujui setelah Fase 0 (commit `18b77e5` di BE, `d02aa0e` di FE); nomor 12–14 disetujui setelah Fase 1.

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
12. Signed URL file private (A7 "File private", `GET /media/{token}`, B5): `*_url` file private bisa langsung dipakai di `<img>` / `<a>` tanpa header Authorization dan berlaku 30 menit. Hak akses dicek saat URL dibuat di Resource; route media tidak memakai `auth:sanctum` dan hanya memvalidasi signature, masa berlaku, dan token.
13. Profil guru milik Kepala Sekolah tetap bisa dibuka dan diubah lewat `GET/PUT /guru/{id}`. `PATCH /guru/{id}/status` dan perubahan `bisa_kelola_keuangan` untuk profil itu ditolak dengan 422 `BUSINESS_RULE`.
14. Pemetaan status HTTP di luar daftar A7: 405 → 404 `NOT_FOUND`; 413 → 422 `VALIDATION_ERROR` dengan pesan "Ukuran file terlalu besar. Maksimal 5 MB per file."; 4xx lain → 422 `VALIDATION_ERROR`; 503 → 503 `SERVER_ERROR`.

## Keputusan teknis

Disetujui di Fase 0 (belum semuanya dipakai; diterapkan di fase terkait):

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

Diambil selama Fase 2:

- Enum `JenisKelamin` (`L`, `P`) untuk kolom `jenis_kelamin` di `guru`, `murid`, dan `pendaftaran`. Nilainya dari A4; A5 tidak mendaftarkannya, jadi kontrak tidak berubah.
- `guru.jenis_kelamin` nullable karena profil guru Kepala Sekolah dibuat seeder dari `.env` yang tidak memuat jenis kelamin. Validasi pendaftaran dan pembuatan guru (Fase 3) tetap mewajibkannya.
- Kolom lain yang dibuat nullable karena desain tidak menyebut dan datanya memang bisa belum ada: `kegiatan_kelas.tema` dan `deskripsi`, `rapor_detail.deskripsi` (baris dibuat kosong saat `POST /rapor`), `rapor.catatan_guru`, `agenda.deskripsi`, `galeri_album.deskripsi` dan `cover_path`, `pendaftaran.nama_ayah`/`pekerjaan_ayah`/`nama_ibu`/`pekerjaan_ibu` (anak bisa hanya punya satu orang tua/wali), `pengaturan.nilai` (beberapa kunci bernilai null, misal `ppdb.tahun_ajaran_id`).
- Unique tambahan: `tahun_ajaran.nama` dan `kelas (tahun_ajaran_id, nama)`, supaya tidak ada dua "2026/2027" atau dua "TK A1" di tahun ajaran yang sama.
- `murid_wali` memakai primary key gabungan `(murid_id, wali_murid_id)` dan hanya `created_at`. Model pivot `MuridWali` meng-override `getUpdatedAtColumn()` ke `null`, karena pivot Laravel memakai nama kolom timestamp milik model induk sehingga konstanta `UPDATED_AT` di pivot tidak berlaku.
- `kelas_murid` punya `id` dan timestamp (statusnya diperbarui saat kenaikan kelas), memakai model pivot `KelasMurid`. `pengumuman_kelas` dan `pengumuman_murid` pivot murni tanpa `id`/timestamp.
- Scope memakai konvensi `scopeVisibleTo` (bukan atribut `#[Scope]`) supaya sama dengan nama di B4.
- Kode PPDB memakai tahun dari tahun ajaran tujuan (`PPDB-2027-0001` untuk TA 2027/2028).
- `SuperAdminSeeder` membaca `config('superadmin.*')`, bukan `env()`, supaya tetap bekerja saat konfigurasi di-cache. Seeder menolak kalau email dipakai akun non-Kepala Sekolah atau sudah ada Kepala Sekolah aktif dengan email lain.
- `PengaturanSeeder` hanya mengisi nilai yang tidak mengarang fakta sekolah: nama sekolah, visi, misi, hero, dua program (Kelompok A/B), dan nilai keuangan/PPDB dari A4. NPSN, alamat, kontak, logo, peta, sejarah, sambutan, fasilitas, dan keunggulan dibiarkan kosong untuk diisi lewat CMS.
- `DemoSeeder` dipecah per domain di `database/seeders/Demo/`. Email akun demo memakai domain `.test` (tidak bisa menerima email sungguhan). Alamat, telepon, email sekolah, dan rekening di `WebsiteDemoSeeder` fiktif.
- Tanggal data demo mengikuti waktu penulisan (September 2026): SPP Juli–September, rapor semester 1 sudah ada yang terbit, PPDB 2027/2028 sedang dibuka. Jumlah murid 61: 60 murid di kelas ditambah satu murid dari pendaftaran PPDB yang diterima (belum punya kelas karena kelas 2027/2028 belum dibuat).

Diambil selama Fase 4 (lihat juga "Keputusan menunggu review"):

- Scope dipakai di dua tempat yang sama: query daftar (`visibleTo` / `diampuOleh`) dan Policy `view` untuk detail, supaya daftar dan detail tidak pernah berbeda. Pembatasan per role tetap di middleware `role:` (B4).
- `KelasService` memegang CRUD kelas dan penempatan; `KenaikanKelasService` terpisah karena alurnya panjang dan memakai aturan penempatan yang sama (`pastikanMuridAktif`, `pastikanBelumPunyaKelas`, `pastikanMuatKapasitas`). Penempatan mengunci baris kelas dan murid (`lockForUpdate`) di dalam transaksi supaya dua penempatan bersamaan tidak melewati kapasitas atau menaruh murid di dua kelas.
- NIS diambil dari NIS terbesar berawalan `TA{tahun masuk}` (termasuk murid yang di-soft delete, karena kolom `nis` unik) dengan `lockForUpdate`, maksimal 9999 per tahun.
- Relasi `Kelas::muridAktif()` (penempatan `aktif`) dipakai untuk `jumlah_murid` dan kapasitas. `Kelas::muatDetail()` memuat relasi detail kelas di satu tempat.
- `PengaturanService` dibuat minimal (`nilai()`, `rekeningSekolah()`) tanpa cache, karena `PUT /pengaturan` (yang akan meng-invalidate cache) baru ada di Fase 7.
- Foto murid disimpan di disk private folder `murid/` lewat `MediaService`; foto lama dihapus setelah transaksi berhasil.
- `routes/console.php`: contoh command `inspire` bawaan skeleton dihapus, diganti jadwal `kode-tautan:bersihkan`.

Diambil selama Fase 3:

- `MediaService` dan `GET /media/{token}` dikerjakan di Fase 3, bukan Fase 4, karena `/auth/me` untuk wali memuat `anak[].foto_url` yang berupa signed URL.
- Parameter route ditulis `{id}` persis seperti A7 (`Route::pattern('id', '[0-9]+')`), sehingga id bukan angka langsung 404. Controller mengambil data dengan `findOrFail`, bukan route model binding.
- `GET /wali/anak` (A7 tidak merinci bentuknya) memakai `AnakWaliResource`: `id, nis, nama_lengkap, nama_panggilan, jenis_kelamin, tanggal_lahir, kelas {id, nama} | null, foto_url, hubungan, is_kontak_utama`. `catatan_khusus` dan data sensitif lain (NIK, alamat) tidak ikut; data lengkap murid ada di `GET /murid/{id}` (Fase 4). Resource yang sama dipakai untuk `anak` di `GET /wali-murid/{id}` dan respons `POST /wali/tautkan-anak`.
- Notifikasi `anak_tertaut` dikirim ke Kepala Sekolah (url `/dashboard/murid/{id}`) dan wali lain yang sudah tertaut ke anak yang sama (url `/dashboard/anak`), supaya penautan oleh orang yang tidak dikenal cepat ketahuan. Wali yang menautkan tidak dikirimi.
- `guru_baru` dikirim ke Kepala Sekolah aktif dengan url `/dashboard/guru/{id}`. Semua notifikasi database memakai kelas dasar `App\Notifications\NotifikasiDatabase` (bentuk `{ jenis, judul, pesan, url }`) dan lewat queue.
- Kode tautan dinormalisasi sebelum validasi (huruf besar, spasi dan tanda hubung dibuang), karena kode sering disalin dari pesan WhatsApp. Kode salah, kedaluwarsa, dan tanggal lahir tidak cocok dibalas 422 `VALIDATION_ERROR` dengan pesan berbeda di field `kode` / `tanggal_lahir`; anak yang sudah tertaut dibalas `BUSINESS_RULE`.
- `GET /guru` dan `GET /wali-murid` menerima `sort` (`nama`, `created_at`, awali `-` untuk menurun; bawaan `nama`), `per_page` (bawaan 15, maksimal 100), dan `search`. Parameter di luar daftar ditolak 422.
- `PATCH /wali-murid/{id}/status` mencabut semua token saat menonaktifkan, sama seperti guru, dan dicatat di activity log `akun`.
- Profil guru Kepala Sekolah dibuat `SuperAdminSeeder` dengan `bisa_kelola_keuangan = true`, supaya data di `GET /guru/{id}` sesuai kenyataan. Nilai yang dikirim ulang tanpa perubahan di `PUT /guru/{id}` diterima; yang mengubahnya ditolak `BUSINESS_RULE`.
- Password awal dari `POST /guru`: 10 karakter huruf dan angka tanpa simbol, supaya mudah didiktekan.
- Enum `Perangkat` (`web`, `mobile`) untuk nama token.
- `lang/id.json` berisi terjemahan teks template email bawaan Laravel (tautan cadangan dan hak cipta). Atribut validasi (`name` → "nama", `no_hp` → "nomor HP", dan seterusnya) ditambahkan di `lang/id/validation.php`.
- Scramble: respons 403 middleware didokumentasikan lewat `ResponsErrorRouteExtension` (lihat "Dokumentasi API"). Skema error A7 dibentuk di satu tempat, `App\Support\Scramble\SkemaErrorA7`.

## Rencana yang sudah disepakati untuk fase berikutnya

- Fase 5: `DemoSeeder` memakai `TagihanService`/`PembayaranService` untuk nomor INV/PAY dan perhitungan potongan. Kode tautan demo sudah dibuat lewat `KodeTautanService::buat()` sejak Fase 4.

## Akun seed

- Kepala Sekolah: email dan password dari `SUPERADMIN_EMAIL` / `SUPERADMIN_PASSWORD` di `.env`.
- Guru demo (`DemoSeeder`), password `guru2026`:

| Email | Keterangan |
|---|---|
| `siti.rahmawati@guru.tkta8.test` | petugas keuangan (`bisa_kelola_keuangan`), guru pendamping TK B1 |
| `nur.aini@guru.tkta8.test` | wali kelas TK A1 |
| `dwi.lestari@guru.tkta8.test` | wali kelas TK A2 |
| `sri.wahyuni@guru.tkta8.test` | wali kelas TK B1 |
| `endang.susilowati@guru.tkta8.test` | wali kelas TK B2 |
| `rina.kusumawati@guru.tkta8.test` | guru pendamping TK A1 |
| `fitri.handayani@guru.tkta8.test`, `ahmad.fauzi@guru.tkta8.test` | status `pending` (menunggu persetujuan) |

- Kode tautan demo: murid yang belum punya wali tertaut. Lihat lewat Tinker: `App\Models\Murid::whereNotNull('kode_tautan')->get(['nama_panggilan', 'tanggal_lahir', 'kode_tautan'])`.
- Wali murid demo (44 dari keluarga murid + 3 pendaftar PPDB baru, email `@wali.tkta8.test`) hanya bisa login lewat Google. Untuk mencoba API sebagai wali di lokal, buat token lewat Tinker: `php artisan tinker` lalu `App\Models\User::where('role', 'wali_murid')->first()->createToken('web')->plainTextToken`.

## Changelog

### Fase 4

File baru:

- `app/Policies/{KelasPolicy, MuridPolicy, TagihanPolicy}.php`: otorisasi per data, di luar jangkauan dibalas 404.
- `app/Http/Controllers/Api/V1/TahunAjaran/TahunAjaranController.php`, `Kelas/{KelasController, PenempatanMuridController}.php`, `Murid/MuridController.php`, `Tagihan/TagihanController.php`.
- `app/Http/Requests/TahunAjaran/{DaftarTahunAjaranRequest, SimpanTahunAjaranRequest}.php`, `Kelas/{DaftarKelasRequest, SimpanKelasRequest, TempatkanMuridRequest, KenaikanKelasRequest}.php`, `Murid/{DaftarMuridRequest, SimpanMuridRequest}.php`, `Tagihan/DaftarTagihanRequest.php`.
- `app/Http/Resources/{TahunAjaranResource, KelasResource, MuridResource, TagihanResource, PembayaranResource}.php`.
- `app/Services/{TahunAjaranService, KelasService, KenaikanKelasService, MuridService, PengaturanService}.php`.
- `app/Console/Commands/BersihkanKodeTautanCommand.php`: `kode-tautan:bersihkan [--dry-run]`.
- Test: `tests/Feature/TahunAjaran/TahunAjaranTest.php`, `Kelas/{KelasTest, PenempatanMuridTest, KenaikanKelasTest}.php`, `Murid/{AksesMuridTest, ManajemenMuridTest, KodeTautanDanWaliTest}.php`, `Tagihan/AksesTagihanTest.php`.

File yang diubah:

- `routes/api.php`: 21 operasi Fase 4; pola angka untuk parameter `murid_id` dan `wali_murid_id`.
- `routes/console.php`: jadwal `kode-tautan:bersihkan` harian 01:00.
- `app/Models/Kelas.php` (`muridAktif()`, `muatDetail()`, `KAPASITAS_BAWAAN`), `Murid.php` (`scopeCari`).
- `app/Services/KodeTautanService.php`: `buat()` dan `bersihkanKedaluwarsa()`.
- `database/seeders/Demo/SekolahDemoSeeder.php`: kode tautan demo dibuat lewat `KodeTautanService::buat()`.
- `lang/id/validation.php`: nama atribut field Fase 4.
- `tests/Feature/DokumentasiApiTest.php`: 404 terdokumentasi untuk detail murid, kelas, dan tagihan.
- `storage/api-docs/api.json`, `dokumentasi.md`.

Hasil pengecekan: 301 test lulus di SQLite dan di MariaDB 12.3.3; Pint, PHPStan, dan `check:slop` tanpa temuan. Endpoint baru juga dicoba lewat `php artisan serve` dengan data demo (guru `nur.aini` hanya melihat TK A1 beserta 15 murid dan 60 tagihannya, kelas lain dibalas 404; wali hanya melihat anaknya).

### Fase 0

- `PROMPT_BE_TK.md`: spesifikasi proyek, lalu diperbarui dengan perubahan yang disetujui (lihat "Perubahan dari spesifikasi awal").
- `CLAUDE.md`: aturan kerja agent (baca spesifikasi dan dokumentasi, satu fase per sesi, patuhi Bagian C).

### Fase 3

Revisi setelah laporan Fase 3:

- `app/Exceptions/LayananBelumDikonfigurasiException.php` (baru): `POST /auth/google` dengan `GOOGLE_CLIENT_ID` kosong membalas 503 `SERVER_ERROR` "Login Google belum dikonfigurasi. Hubungi pihak sekolah." (sebelumnya 500); penyebabnya tetap tercatat di log.
- `app/Services/GoogleIdTokenVerifier.php`, `GoogleLoginService.php`, `app/Exceptions/ApiExceptionRenderer.php`: melempar dan merender exception itu.
- `app/Support/Scramble/{ApiErrorResponseExtension, ResponsErrorRouteExtension, SkemaErrorA7}.php`: respons 503 terdokumentasi di `POST /auth/google`; status respons error bisa berbeda dari status bawaan kodenya.
- `tests/Feature/Auth/LoginGoogleTest.php`, `tests/Feature/DokumentasiApiTest.php`: test respons 503, isi log, dan dokumentasinya.
- `.env.example` sudah memakai `MAIL_MAILER=log`; tidak diubah.
- `dokumentasi.md`: bagian "Serah terima ke lingkungan lokal"; migration, seeder, dan test diverifikasi di MariaDB 10.11.14.
- `storage/api-docs/api.json`: diekspor ulang.

File baru:

- `app/Http/Controllers/Api/V1/Auth/{AuthController, RegistrasiGuruController, ResetPasswordController, ProfilController}.php`, `Guru/GuruController.php`, `WaliMurid/WaliMuridController.php`, `Wali/{ProfilWaliController, AnakController}.php`, `MediaController.php`.
- `app/Http/Requests/Auth/*` (7 request), `Guru/{DaftarGuruRequest, SimpanGuruRequest, TolakGuruRequest}.php`, `WaliMurid/DaftarWaliMuridRequest.php`, `Wali/{LengkapiProfilWaliRequest, TautkanAnakRequest}.php`, `UbahStatusAkunRequest.php`, `Concerns/MemvalidasiDaftar.php` (aturan `page`, `per_page`, `search`, `sort`).
- `app/Http/Resources/{UserResource, AkunResource, GuruResource, WaliMuridResource, AnakWaliResource}.php`.
- `app/Services/{AuthService, GoogleIdTokenVerifier, GoogleLoginService, GuruService, WaliMuridService, KodeTautanService, MediaService}.php`.
- `app/Notifications/{NotifikasiDatabase, GuruBaruNotification, AnakTertautNotification, GuruDisetujuiNotification, GuruDitolakNotification, ResetPasswordNotification}.php`.
- `app/Exceptions/AksesAkunDitolakException.php`, `app/Enums/Perangkat.php`, `app/Rules/NomorHp.php`.
- `app/Support/Scramble/{ResponsErrorRouteExtension, SkemaErrorA7}.php`.
- `lang/id.json`.
- Test: `tests/Feature/Auth/{LoginTest, LoginGoogleTest, RegistrasiGuruTest, ResetPasswordTest, SesiDanProfilTest}.php`, `Guru/{ManajemenGuruTest, PersetujuanGuruTest}.php`, `WaliMurid/ManajemenWaliMuridTest.php`, `Wali/{TautkanAnakTest, ProfilDanAnakWaliTest}.php`, `Media/MediaPrivatTest.php` (100 test, dihitung per dataset).

File yang diubah:

- `routes/api.php`: 24 operasi Fase 3.
- `app/Exceptions/ApiExceptionRenderer.php`: `AksesAkunDitolakException`, `InvalidSignatureException`, `InvalidQuery`; pesan 404 dibedakan antara data dan endpoint.
- `app/Providers/AppServiceProvider.php`: `Password::defaults()`, Gate `kelola-keuangan`, rate limiter `login`, `login-google`, `tautkan-anak`.
- `app/Models/User.php` (`scopeKepalaSekolahAktif`, `profilWaliMurid()`, notifikasi reset password FE), `Guru.php` (`milikKepalaSekolah()`, `scopeBukanKepalaSekolah`, `scopeCari`), `WaliMurid.php` (`scopeCari`), `Murid.php` (`kelasAktif()`).
- `app/Support/Scramble/ApiErrorResponseExtension.php`: satu exception bisa dipetakan ke beberapa kode (`AksesAkunDitolakException` → `ACCOUNT_*`); skema dari `SkemaErrorA7`.
- `config/app.php` (`frontend_url`), `config/services.php` (`google.client_id`), `config/scramble.php` (extension baru), `.env.example` (`GOOGLE_CLIENT_ID`).
- `database/seeders/SuperAdminSeeder.php`: profil guru Kepala Sekolah dengan `bisa_kelola_keuangan = true`.
- `database/factories/TahunAjaranFactory.php`: tahun acak diambil dari 1990–2020. Sebelumnya 2015–2045, sehingga test yang menulis nama `2025/2026` langsung sesekali gagal karena nama tahun ajaran bentrok (unique).
- `lang/id/validation.php`: nama atribut field Fase 3.
- `phpunit.xml`: `MAIL_FROM_ADDRESS` dan `FRONTEND_URL` untuk test.
- `tests/Pest.php` (helper `buatKepalaSekolah()`, `buatGuru()`), `tests/Feature/DokumentasiApiTest.php` (memeriksa kode error 401/403/404/429 per operasi). Total test sekarang 189.
- `storage/api-docs/api.json`, `dokumentasi.md`.

### Fase 2

File baru:

- `database/migrations/0001_01_01_000004_create_cache_locks_table.php`, `0001_01_01_000005_create_failed_jobs_table.php`: dipisah dari migration bawaan (satu migration per tabel).
- `database/migrations/2026_09_26_153852_create_notifications_table.php`, `2026_09_26_153853_create_activity_log_table.php` (spatie, ditambah `down()`).
- `database/migrations/2026_09_26_160001` … `160025`: 25 tabel domain A4 sesuai urutan dependensi (guru, wali_murid, tahun_ajaran, murid, murid_wali, kelas, kelas_murid, jenis_tagihan, keringanan, tagihan, pembayaran, pengumuman, pengumuman_kelas, pengumuman_murid, agenda, kegiatan_kelas, kegiatan_foto, elemen_penilaian, rapor, rapor_detail, pendaftaran, pendaftaran_dokumen, galeri_album, galeri_foto, pengaturan).
- `app/Enums/JenisKelamin.php`.
- `app/Models/*.php`: 23 model domain dengan `$table` eksplisit, relasi ERD, cast enum/tanggal, dan scope B4. Pivot `MuridWali` dan `KelasMurid`.
- `database/factories/*.php`: factory untuk semua model domain, data Indonesia (alamat Semarang lewat trait `Concerns/MembuatAlamatSemarang`, NIK berawalan kode wilayah Semarang 3374, HP `081…`), tanpa teks lorem.
- `config/superadmin.php`.
- `database/seeders/SuperAdminSeeder.php`, `ElemenPenilaianSeeder.php`, `PengaturanSeeder.php`, `DemoSeeder.php`, dan `Demo/{GambarContoh, SekolahDemoSeeder, KeuanganDemoSeeder, AkademikDemoSeeder, KomunikasiDemoSeeder, PpdbDemoSeeder, WebsiteDemoSeeder}.php`.
- `tests/Feature/Database/RelasiModelTest.php`, `ScopeVisibilitasTest.php`, `SeederTest.php`.

File yang diubah:

- `database/migrations/0001_01_01_000001_create_cache_table.php`, `0001_01_01_000002_create_jobs_table.php`: hanya satu tabel per file; `job_batches` dihapus.
- `app/Models/User.php`: relasi `guru()`, `waliMurid()`, dan `bisaKelolaKeuangan()`.
- `database/seeders/DatabaseSeeder.php`: memanggil seeder wajib.
- `.env.example`: `SUPERADMIN_NAME`, `SUPERADMIN_EMAIL`, `SUPERADMIN_PASSWORD`.
- `tests/Unit/EnumKontrakTest.php`: menyertakan `JenisKelamin`.
- `dokumentasi.md`.

### Fase 1

Revisi setelah laporan Fase 1:

- `PROMPT_BE_TK.md` (Bagian A, B5, B6.7) dan Bagian A `PROMPT_FE_TK.md`: perubahan spesifikasi nomor 12–14.
- `CLAUDE.md` (kedua repo): aturan laporan fase dalam Bahasa Indonesia.
- `app/Exceptions/ApiExceptionRenderer.php`: pesan 413 menjadi "Ukuran file terlalu besar. Maksimal 5 MB per file." (konstanta `PESAN_UNGGAHAN_TERLALU_BESAR`).
- `tests/Feature/FormatErrorTest.php`: test untuk respons 413.

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
- `tests/Pest.php` dan test: `HealthTest`, `FormatErrorTest`, `FormatSuksesTest`, `MiddlewareAksesTest`, `CorsTest`, `DokumentasiApiTest`, `SerialisasiTanggalTest`, `Unit/EnumKontrakTest` (60 test).
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
