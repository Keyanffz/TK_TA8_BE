# Dokumentasi Backend TK Tarbiyathul Athfal 8

REST API untuk sistem informasi TK Tarbiyathul Athfal 8. Dipakai oleh frontend Next.js dan nanti aplikasi Flutter. Acuan desain dan kontrak API: `PROMPT_BE_TK.md` (Bagian A sama persis dengan `PROMPT_FE_TK.md` di repo FE).

## Status

| Fase | Status |
|---|---|
| 0. Analisis | Selesai, rencana disetujui |
| 1. Fondasi | Selesai |
| 2. Database | Selesai, menunggu konfirmasi |
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

Database: MySQL 8 (utf8mb4). Migration, rollback, dan seeder sudah dijalankan di MySQL 8.0.46. Test memakai SQLite in-memory (`phpunit.xml`); seluruh test juga lulus saat dijalankan ke MySQL.

## Instalasi dan menjalankan

Prasyarat: PHP 8.4 dengan ekstensi `pdo_mysql`, `mbstring`, `gd`, `zip`, `intl`, `dom`, `xml`, `fileinfo`; Composer 2; MySQL 8.

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
| `SUPERADMIN_NAME`, `SUPERADMIN_EMAIL`, `SUPERADMIN_PASSWORD` | Akun Kepala Sekolah untuk `SuperAdminSeeder` (lewat `config/superadmin.php`). Password minimal 8 karakter berisi huruf dan angka; seeder berhenti dengan pesan jelas kalau kosong atau tidak valid |

Variabel yang akan ditambahkan saat dipakai: `GOOGLE_CLIENT_ID` (Fase 3).

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
| Unggahan melebihi `post_max_size` (413) | 422 | `VALIDATION_ERROR` ("Ukuran file terlalu besar. Maksimal 5 MB per file.") |
| Status 4xx lain di luar daftar A7 | 422 | `VALIDATION_ERROR` |
| Mode pemeliharaan | 503 | `SERVER_ERROR` |
| Exception lain | 500 | `SERVER_ERROR` (detail tidak dikirim ke klien, tetap tercatat di log) |

Middleware:

- `ForceJsonResponse`: dipasang di grup `api`, memaksa `Accept: application/json`.
- `akun.aktif` (`EnsureAccountActive`): token milik akun selain `aktif` ditolak 403 dengan `ACCOUNT_PENDING` / `ACCOUNT_REJECTED` / `ACCOUNT_INACTIVE`.
- `role:super_admin,guru` (`EnsureRole`): role di luar daftar ditolak 403 `FORBIDDEN`. Nama role yang salah ketik di route memicu error 500 supaya cepat ketahuan.

## Skema database

- 35 tabel: 26 tabel domain A4 (termasuk `users`), bawaan Laravel (`cache`, `cache_locks`, `jobs`, `failed_jobs`, `password_reset_tokens`, `personal_access_tokens`, `notifications`, `migrations`), dan `activity_log` (spatie). Satu migration per tabel; `job_batches` dan `sessions` bawaan tidak dibuat karena tidak dipakai.
- Foreign key: tabel anak dan pivot `cascade` (`murid_wali`, `kelas_murid`, `pengumuman_kelas`, `pengumuman_murid`, `kegiatan_foto`, `rapor_detail`, `pendaftaran_dokumen`, `galeri_foto`, profil `guru`/`wali_murid` ke `users`); kolom pelaku (`dibuat_oleh`, `diverifikasi_oleh`, `disetujui_oleh`, `diproses_oleh`, `dibayar_oleh`) `null on delete`; `kelas.wali_kelas_id`, `kelas.guru_pendamping_id`, `pendaftaran.murid_id` `null on delete`; sisanya (data keuangan, rapor, kegiatan, tahun ajaran) `restrict`. `users`, `murid`, dan `pengumuman` memakai soft delete, jadi hapus fisik jarang terjadi.
- Cascade di database tidak menjalankan observer Eloquent. Penghapusan data yang punya file (kegiatan, rapor, PPDB, galeri) harus lewat Eloquent di service supaya file fisiknya ikut terhapus (B5).
- Scope visibilitas (B4), dipakai semua endpoint terkait mulai Fase 3:

| Scope | Kepala Sekolah | Guru | Wali murid |
|---|---|---|---|
| `Kelas::diampuOleh($user)` | – | kelas di tahun ajaran aktif tempat dia wali kelas atau guru pendamping | – |
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

## Rencana yang sudah disepakati untuk fase berikutnya

- Fase 4 dan 5: setelah `KodeTautanService`, `TagihanService`, dan `PembayaranService` ada, `DemoSeeder` memakai service itu untuk kode tautan, nomor INV/PAY, dan perhitungan potongan. Sekarang seeder menghitungnya sendiri dengan format yang sama.
- Fase 3: `ApiErrorResponseExtension` (atau extension Scramble terpisah) juga mendokumentasikan respons 403 dari middleware, yaitu `ACCOUNT_PENDING`, `ACCOUNT_REJECTED`, `ACCOUNT_INACTIVE` dari `akun.aktif` dan `FORBIDDEN` dari `role:...`, supaya `api.json` lengkap untuk FE.

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

- Wali murid demo (44 dari keluarga murid + 3 pendaftar PPDB baru, email `@wali.tkta8.test`) hanya bisa login lewat Google. Untuk mencoba API sebagai wali di lokal, buat token lewat Tinker: `php artisan tinker` lalu `App\Models\User::where('role', 'wali_murid')->first()->createToken('web')->plainTextToken`.

## Changelog

### Fase 0

- `PROMPT_BE_TK.md`: spesifikasi proyek, lalu diperbarui dengan perubahan yang disetujui (lihat "Perubahan dari spesifikasi awal").
- `CLAUDE.md`: aturan kerja agent (baca spesifikasi dan dokumentasi, satu fase per sesi, patuhi Bagian C).

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
