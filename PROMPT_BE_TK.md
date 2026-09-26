# PROMPT BACKEND — Sistem Informasi TK Tarbiyathul Athfal 8

Kamu adalah senior Laravel engineer. Tugasmu membangun **REST API** untuk sistem informasi sekolah TK Tarbiyathul Athfal 8 di repo `BE_TK_Tarbiyathul_athfal_8`, dari nol, sesuai desain final di Bagian A. Frontend (repo terpisah, Next.js) dan nanti aplikasi Flutter akan memakai API ini, jadi **kontrak API di A7 wajib diikuti persis** (path, nama field, enum, format respons).

## Aturan kerja (WAJIB)

1. **Fase 0 dulu, tanpa menulis kode.** Baca seluruh prompt, cek versi Laravel/PHP/paket yang tersedia sekarang, lalu tulis rencana (urutan migration, daftar paket + kompatibilitasnya, struktur folder, risiko). Tampilkan rencana + pertanyaan (kalau ada), lalu **tunggu konfirmasiku**.
2. Kerjakan **per fase** (Bagian D). Di akhir tiap fase: jalankan `php artisan test`, `./vendor/bin/pint`, `./vendor/bin/phpstan analyse`, `composer check:slop`, pastikan hijau, review ulang kodemu terhadap checklist Bagian C (anti AI-slop), update `dokumentasi.md`, lalu **berhenti dan laporkan** (apa yang dibuat, cara uji, hal yang perlu keputusanku). Jangan lompat ke fase berikutnya tanpa konfirmasi.
3. Tulis **file lengkap**, jangan potongan/diff parsial, jangan ada `// TODO` kosong atau placeholder yang tidak jalan.
4. Kalau ada yang ambigu atau bertentangan dengan desain, **tanyakan**. Jangan mengubah skema/kontrak diam-diam. Kalau kamu yakin ada desain yang perlu diubah, usulkan dulu dengan alasannya.
5. Semua pesan untuk pengguna (validasi, error, notifikasi) dalam **Bahasa Indonesia**. Nama kode (class, method, variabel) boleh campuran sesuai istilah domain di desain (contoh: `TagihanService`, `generateBulanan()`).

## `dokumentasi.md` (WAJIB)

Buat di root repo sejak Fase 1, dan selalu diperbarui. Isinya:
- Ringkasan proyek, stack + versi terpasang, cara install & menjalankan (termasuk queue & scheduler)
- Daftar variabel `.env` penting
- **Changelog per fase**: setiap file baru dan file lama yang diubah, beserta alasan singkat
- Keputusan teknis yang diambil selama pengerjaan
- Status: fase selesai, sedang dikerjakan, dan yang tersisa
- Akun seed untuk testing

Dokumen ini jadi acuan saat aku meminta prompt lanjutan, jadi harus akurat.
---

# BAGIAN A — DESAIN SISTEM (FINAL, JANGAN DIUBAH TANPA KONFIRMASI)

> Bagian ini sama persis di prompt FE dan BE. Ini acuan tunggal. Kalau kamu (agent) menemukan konflik atau hal yang tidak masuk akal, **tanyakan dulu**, jangan mengarang sendiri.

## A1. Ringkasan Sistem

Sistem Informasi Sekolah **TK Tarbiyathul Athfal 8** berbasis web (dan nanti mobile Flutter memakai API yang sama).

- **2 repo terpisah:**
  - `BE_TK_Tarbiyathul_athfal_8` → Laravel (REST API, token Sanctum, dokumentasi OpenAPI via Scramble)
  - `FE_TK_Tarbiyathul_athfal_8` → Next.js (App Router) + TypeScript + Tailwind CSS + shadcn/ui
- **3 role:**
  | Role (kode) | Siapa | Level |
  |---|---|---|
  | `super_admin` | Kepala Sekolah | Super Admin |
  | `guru` | Guru | Admin |
  | `wali_murid` | Orang tua / wali | User |
- **Murid TIDAK punya akun login.** Murid hanya data. Semua akses anak lewat akun wali murid (1 wali bisa punya beberapa anak, 1 anak bisa punya beberapa wali, misal ayah & ibu).
- **Tidak ada modul absensi.**
- Satu **dashboard bersama** (`/dashboard`) untuk semua role; menu, isi beranda, dan aksi menyesuaikan role.
- **Hanya super admin** yang bisa mengubah konten website publik (landing page, profil sekolah, galeri) dan pengaturan sistem.

## A2. Keputusan Desain Penting

1. **Login:**
   - Kepala Sekolah & Guru → email + password.
   - Wali Murid → Google Sign-In saja (daftar sendiri otomatis saat pertama login).
   - Akun Kepala Sekolah dibuat lewat seeder (hanya 1 akun `super_admin` aktif), sekaligus profil `guru` miliknya (jabatan "Kepala Sekolah") supaya Kepala Sekolah bisa mencatat kegiatan kelas, menjadi wali kelas bila perlu, dan tampil di daftar guru landing. Profil guru ini tidak muncul di `GET /guru`, tidak bisa dinonaktifkan, izin keuangannya tidak bisa diubah, dan tidak dihitung sebagai guru di statistik dashboard; profil ini tetap bisa dibuka dan diubah lewat `GET/PUT /guru/{id}`.
   - Guru bisa daftar sendiri → status `pending` → harus **disetujui Kepala Sekolah** baru bisa login. Kepala Sekolah juga bisa membuat akun guru langsung (status langsung `aktif`).
2. **Menautkan anak ke wali:** sekolah (super admin) generate **kode tautan** per murid (8 karakter, berlaku 14 hari). Wali memasukkan kode + tanggal lahir anak → langsung tertaut. Kode bisa dipakai lebih dari 1 wali (ayah & ibu) selama belum kedaluwarsa. Super admin bisa melepas tautan.
3. **PPDB online:** wali bisa mendaftarkan anak baru lewat dashboard saat PPDB dibuka. Pendaftaran selalu untuk tahun ajaran di pengaturan `ppdb.tahun_ajaran_id` (biasanya tahun ajaran berikutnya, bukan yang sedang aktif). PPDB tidak bisa dibuka (`ppdb.dibuka = true` ditolak) kalau `ppdb.tahun_ajaran_id` belum diisi atau tahun ajarannya tidak ada. Kalau diterima, sistem otomatis membuat data murid dan menautkannya ke wali tersebut.
4. **Tagihan (SPP) otomatis:** scheduler membuat tagihan bulanan tiap tanggal 1 untuk semua murid aktif, berdasarkan `jenis_tagihan` berperiode `bulanan` yang aktif di tahun ajaran aktif. Idempoten (tidak dobel, dijaga unique index). Potongan dari tabel `keringanan` otomatis diterapkan.
5. **Pembayaran:** transfer manual ke rekening sekolah + upload bukti oleh wali → diverifikasi. Petugas keuangan juga bisa mencatat pembayaran tunai, atau transfer yang sudah masuk ke rekening sekolah (bukti opsional); keduanya otomatis diterima. **Tidak ada cicilan** (1 tagihan dibayar penuh). Payment gateway (Midtrans) = pengembangan nanti, bukan sekarang.
6. **Petugas keuangan:** super admin, ditambah guru yang diberi izin `bisa_kelola_keuangan = true` oleh super admin (untuk guru yang merangkap bendahara).
7. **Tunggakan:** tagihan lewat jatuh tempo otomatis berstatus `terlambat` dan wali dapat notifikasi. Super admin/guru juga bisa membuat pengumuman dengan target `murid` tertentu (misal yang menunggak).
8. **Rapor perkembangan anak (Kurikulum Merdeka PAUD):** penilaian naratif per elemen. Elemen bisa dikelola super admin (seed awal: Nilai Agama & Budi Pekerti; Jati Diri; Dasar-dasar Literasi, Matematika, Sains, Teknologi, Rekayasa & Seni). Alur: guru draft → ajukan → kepala sekolah terbitkan atau minta revisi → wali bisa lihat & unduh PDF.
9. **Privasi foto anak:** foto kegiatan kelas, bukti bayar, dokumen PPDB, dan foto rapor disimpan di disk **private**, diakses via endpoint terotorisasi / signed URL. Hanya galeri publik & aset landing yang di disk public.
10. **Uang** disimpan sebagai integer rupiah (tanpa desimal). **Zona waktu** `Asia/Jakarta`, bahasa `id`.
11. **Notifikasi** memakai Laravel database notifications (channel mail opsional). Push notification (FCM) nanti saat Flutter.

## A3. Use Case per Aktor

**Publik (tanpa login)**
- Melihat landing page (profil, visi-misi, program, fasilitas, guru, galeri, pengumuman publik, agenda publik, info PPDB, kontak)
- Melihat daftar & detail pengumuman publik, galeri
- Login (guru/kepsek), login Google (wali), daftar sebagai guru, lupa/reset password (guru/kepsek)

**Wali Murid (User)**
- Login Google, lengkapi profil (onboarding)
- Tautkan anak dengan kode tautan; lihat daftar anak; pindah anak aktif (switcher)
- Beranda: ringkasan tagihan, kegiatan kelas terbaru, pengumuman, agenda, rapor terbaru
- Lihat tagihan anak, bayar (upload bukti transfer), lihat riwayat pembayaran, unduh kwitansi
- Lihat kegiatan/dokumentasi kelas anak (foto)
- Lihat & unduh rapor yang sudah terbit
- Lihat pengumuman yang relevan (semua / wali / kelas anak / anaknya)
- Lihat agenda sekolah
- Daftar PPDB untuk anak baru & pantau statusnya
- Notifikasi, edit profil

**Guru (Admin)**
- Daftar akun (menunggu persetujuan), login email
- Beranda: kelas saya, jumlah murid, progres rapor, kegiatan terakhir, pengumuman
- Kelas saya: daftar murid kelas yang diampu (sebagai wali kelas / pendamping), detail murid & kontak wali
- Kelola kegiatan kelas (judul, tema, deskripsi, foto)
- Kelola rapor murid kelasnya (draft, isi per elemen, ajukan, perbaiki saat revisi)
- Buat pengumuman untuk kelasnya / murid di kelasnya
- Lihat status tagihan murid kelasnya (read-only)
- Lihat agenda
- **Jika `bisa_kelola_keuangan`**: akses menu keuangan seperti super admin (kecuali pengaturan rekening & jenis tagihan)
- Notifikasi, edit profil, ganti password

**Kepala Sekolah (Super Admin)**
- Semua yang bisa guru lakukan (lihat semua kelas)
- Beranda: statistik sekolah + daftar tindakan tertunda (guru pending, pembayaran menunggu verifikasi, rapor menunggu review, pendaftar PPDB baru)
- Kelola guru: tambah, edit, setujui/tolak pendaftaran, aktif/nonaktifkan, beri izin keuangan, tampilkan di landing
- Kelola tahun ajaran (aktifkan 1), kelas (wali kelas, pendamping, kapasitas), penempatan murid, kenaikan kelas massal
- Kelola murid (CRUD, status, generate kode tautan, lepas tautan wali), lihat wali murid
- Keuangan: jenis tagihan, keringanan, generate tagihan manual (idempoten), tagihan sekali bayar (uang pangkal/seragam), verifikasi pembayaran, catat pembayaran tunai/transfer, batalkan tagihan, laporan & ekspor Excel, daftar tunggakan
- Rapor: review, terbitkan, minta revisi; kelola elemen penilaian
- Pengumuman (semua target) & agenda
- PPDB: buka/tutup, verifikasi, terima (pilih kelas), tolak
- **CMS website:** profil sekolah, konten landing, galeri
- Pengaturan: rekening sekolah, tanggal jatuh tempo, hari pengingat, info PPDB
- Log aktivitas

## A4. ERD

```mermaid
erDiagram
    users ||--o| guru : "profil guru"
    users ||--o| wali_murid : "profil wali"
    wali_murid ||--o{ murid_wali : ""
    murid ||--o{ murid_wali : ""
    tahun_ajaran ||--o{ kelas : ""
    guru ||--o{ kelas : "wali kelas / pendamping"
    kelas ||--o{ kelas_murid : ""
    murid ||--o{ kelas_murid : ""
    tahun_ajaran ||--o{ jenis_tagihan : ""
    jenis_tagihan ||--o{ tagihan : ""
    murid ||--o{ tagihan : ""
    tahun_ajaran ||--o{ tagihan : ""
    murid ||--o{ keringanan : ""
    jenis_tagihan ||--o{ keringanan : ""
    tagihan ||--o{ pembayaran : ""
    users ||--o{ pembayaran : "membayar / memverifikasi"
    users ||--o{ pengumuman : "menulis"
    pengumuman ||--o{ pengumuman_kelas : ""
    kelas ||--o{ pengumuman_kelas : ""
    pengumuman ||--o{ pengumuman_murid : ""
    murid ||--o{ pengumuman_murid : ""
    users ||--o{ agenda : "membuat"
    kelas ||--o{ kegiatan_kelas : ""
    guru ||--o{ kegiatan_kelas : ""
    kegiatan_kelas ||--o{ kegiatan_foto : ""
    murid ||--o{ rapor : ""
    kelas ||--o{ rapor : ""
    tahun_ajaran ||--o{ rapor : ""
    rapor ||--o{ rapor_detail : ""
    elemen_penilaian ||--o{ rapor_detail : ""
    wali_murid ||--o{ pendaftaran : ""
    tahun_ajaran ||--o{ pendaftaran : ""
    pendaftaran ||--o{ pendaftaran_dokumen : ""
    pendaftaran |o--o| murid : "jadi murid"
    galeri_album ||--o{ galeri_foto : ""

    users {
        bigint id PK
        string email UK
        string role
        string status }
    guru {
        bigint id PK
        bigint user_id FK
        bool bisa_kelola_keuangan }
    wali_murid {
        bigint id PK
        bigint user_id FK }
    murid {
        bigint id PK
        string nis UK
        string kode_tautan UK
        string status }
    murid_wali {
        bigint murid_id FK
        bigint wali_murid_id FK
        string hubungan }
    tahun_ajaran {
        bigint id PK
        string nama
        bool is_aktif }
    kelas {
        bigint id PK
        bigint tahun_ajaran_id FK
        bigint wali_kelas_id FK }
    kelas_murid {
        bigint kelas_id FK
        bigint murid_id FK }
    jenis_tagihan {
        bigint id PK
        string periode
        bigint nominal }
    tagihan {
        bigint id PK
        string kode UK
        date periode
        string status }
    pembayaran {
        bigint id PK
        bigint tagihan_id FK
        string status }
    rapor {
        bigint id PK
        tinyint semester
        string status }
```

### Detail tabel

Semua tabel punya `id` (bigint PK) dan `created_at/updated_at` kecuali pivot yang disebut. Nama tabel bahasa Indonesia → set `$table` eksplisit di model.

- **users**: name, email (unique), password (nullable, wali Google tidak punya), google_id (nullable, unique), role (enum Role), status (enum StatusAkun), no_hp (nullable), avatar_path (nullable), email_verified_at, last_login_at, remember_token, deleted_at (soft delete)
- **guru**: user_id (FK unique), nip (nullable), nuptk (nullable), jenis_kelamin (L/P), tempat_lahir, tanggal_lahir, alamat, pendidikan_terakhir, jabatan (string, misal "Guru Kelas"), foto_path, bisa_kelola_keuangan (bool, default false), tampil_di_landing (bool, default false), disetujui_oleh (FK users, nullable), disetujui_at, alasan_penolakan (nullable)
- **wali_murid**: user_id (FK unique), nik (nullable), pekerjaan, alamat, profil_lengkap (bool, default false)
- **murid**: nis (unique, auto format `TA{tahun}{urut 4 digit}`), nisn (nullable unique), nik (nullable), nama_lengkap, nama_panggilan, jenis_kelamin, tempat_lahir, tanggal_lahir, agama, alamat, anak_ke (nullable), foto_path (nullable), catatan_khusus (nullable, misal alergi makanan / kebutuhan khusus — hanya terlihat guru & kepsek & wali anak itu), status (enum StatusMurid), tanggal_masuk, tanggal_keluar (nullable), kode_tautan (nullable unique), kode_tautan_expired_at (nullable), deleted_at
- **murid_wali** (pivot): murid_id, wali_murid_id, hubungan (enum Hubungan), is_kontak_utama (bool), created_at. Unique (murid_id, wali_murid_id)
- **tahun_ajaran**: nama ("2026/2027"), tanggal_mulai, tanggal_selesai, semester_aktif (1/2), is_aktif (hanya 1 yang true)
- **kelas**: tahun_ajaran_id, nama ("TK A1"), tingkat (enum Tingkat), wali_kelas_id (FK guru, nullable), guru_pendamping_id (FK guru, nullable), kapasitas (int, default 20)
- **kelas_murid**: kelas_id, murid_id, status (enum StatusKelasMurid, default aktif). Unique (kelas_id, murid_id). Aturan: 1 murid hanya boleh 1 kelas per tahun ajaran (validasi di service)
- **jenis_tagihan**: tahun_ajaran_id, nama ("SPP", "Uang Kegiatan", "Seragam"), deskripsi, nominal (unsigned bigint), periode (enum PeriodeTagihan), tingkat (nullable enum Tingkat; null = semua tingkat), is_aktif
- **keringanan**: murid_id, jenis_tagihan_id, tipe (enum TipeKeringanan), nilai (int; persen 1–100 atau rupiah), alasan, berlaku_mulai (date), berlaku_sampai (date nullable), dibuat_oleh (FK users)
- **tagihan**: kode (unique, `INV-YYYYMM-XXXXX`), murid_id, jenis_tagihan_id, tahun_ajaran_id, periode (date nullable, selalu tanggal 1 bulan tsb; null untuk tagihan sekali), nominal, potongan, total, jatuh_tempo (date), status (enum StatusTagihan), lunas_at (nullable), dibuat_oleh (FK users nullable; null = sistem), catatan. **Unique (murid_id, jenis_tagihan_id, periode)**
- **pembayaran**: kode (unique, `PAY-YYYYMMDD-XXXXX`), tagihan_id, dibayar_oleh (FK users nullable), metode (enum MetodeBayar), jumlah, tanggal_bayar, bukti_path (nullable, private), bank_pengirim (nullable), nama_pengirim (nullable), status (enum StatusPembayaran), alasan_penolakan (nullable), diverifikasi_oleh (FK users nullable), diverifikasi_at. 1 tagihan boleh punya banyak percobaan pembayaran, tapi maksimal 1 yang `menunggu` dan 1 yang `diterima`
- **pengumuman**: judul, slug (unique), isi (HTML, disanitasi), lampiran_path (nullable), target (enum TargetPengumuman), is_publik (bool, tampil di landing; hanya boleh jika target `semua`), is_pinned (bool), penulis_id (FK users), published_at (nullable = draft), deleted_at
- **pengumuman_kelas**: pengumuman_id, kelas_id. **pengumuman_murid**: pengumuman_id, murid_id
- **agenda**: judul, deskripsi, tanggal_mulai, tanggal_selesai, jenis (enum JenisAgenda), is_publik, dibuat_oleh
- **kegiatan_kelas**: kelas_id, guru_id, tanggal, tema, judul, deskripsi. **kegiatan_foto**: kegiatan_kelas_id, path (private), caption, urutan
- **elemen_penilaian**: kode, nama, deskripsi, urutan, is_aktif
- **rapor**: murid_id, kelas_id, tahun_ajaran_id, semester (1/2), tinggi_badan (decimal nullable, cm), berat_badan (decimal nullable, kg), catatan_guru, status (enum StatusRapor), catatan_revisi (nullable), dibuat_oleh (FK guru), diajukan_at, disetujui_oleh (FK users nullable), terbit_at. Unique (murid_id, tahun_ajaran_id, semester)
- **rapor_detail**: rapor_id, elemen_penilaian_id, deskripsi (text), foto_path (nullable, private). Unique (rapor_id, elemen_penilaian_id)
- **pendaftaran**: kode (unique, `PPDB-YYYY-XXXX`), wali_murid_id, hubungan (enum Hubungan; hubungan wali pendaftar dengan anak, dipakai saat menautkan ketika diterima), tahun_ajaran_id (diisi dari `ppdb.tahun_ajaran_id` saat mendaftar), tingkat_tujuan, nama_lengkap, nama_panggilan, jenis_kelamin, tempat_lahir, tanggal_lahir, nik, agama, alamat, nama_ayah, pekerjaan_ayah, nama_ibu, pekerjaan_ibu, no_hp, status (enum StatusPendaftaran), catatan (nullable), diproses_oleh (nullable), diproses_at, murid_id (nullable, terisi saat diterima)
- **pendaftaran_dokumen**: pendaftaran_id, jenis (enum JenisDokumen), path (private)
- **galeri_album**: judul, slug, deskripsi, cover_path, tanggal, is_publik. **galeri_foto**: galeri_album_id, path (public), caption, urutan
- **pengaturan**: kunci (unique), nilai (json), grup (string: profil | landing | keuangan | ppdb)
- Tabel bawaan: personal_access_tokens, notifications, password_reset_tokens, jobs, failed_jobs, activity_log (spatie)

### Kunci pengaturan (seed default)

| kunci | isi |
|---|---|
| `profil.nama_sekolah` | "TK Tarbiyathul Athfal 8" |
| `profil.npsn`, `profil.alamat`, `profil.telepon`, `profil.email`, `profil.maps_embed_url` | string |
| `profil.logo` | path gambar |
| `profil.visi` | string |
| `profil.misi` | string[] |
| `profil.sejarah`, `profil.sambutan_kepsek` | string (HTML) |
| `landing.hero` | { judul, subjudul, gambar, cta_teks } |
| `landing.program` | { judul, deskripsi, ikon }[] |
| `landing.fasilitas` | { nama, deskripsi, gambar }[] |
| `landing.keunggulan` | { judul, deskripsi, ikon }[] |
| `keuangan.rekening` | { bank, nomor, atas_nama }[] |
| `keuangan.tanggal_jatuh_tempo` | int (default 10) |
| `keuangan.hari_pengingat` | int (default 3, H-3 sebelum jatuh tempo) |
| `ppdb.dibuka` | bool |
| `ppdb.tanggal_buka`, `ppdb.tanggal_tutup` | date |
| `ppdb.tahun_ajaran_id` | int (id tahun ajaran tujuan PPDB; wajib terisi dengan tahun ajaran yang ada sebelum `ppdb.dibuka` bisa `true`) |
| `ppdb.kuota` | int |
| `ppdb.info` | string (HTML: syarat, biaya, alur) |

## A5. Enum (nilai string, dipakai sama di BE & FE)

- **Role**: `super_admin`, `guru`, `wali_murid`
- **StatusAkun**: `pending`, `aktif`, `ditolak`, `nonaktif`
- **StatusMurid**: `aktif`, `lulus`, `pindah`, `keluar`
- **Hubungan**: `ayah`, `ibu`, `wali`
- **Tingkat**: `A`, `B`
- **StatusKelasMurid**: `aktif`, `naik`, `tinggal`, `lulus`, `keluar`
- **PeriodeTagihan**: `bulanan`, `sekali`
- **TipeKeringanan**: `persen`, `nominal`
- **StatusTagihan**: `belum_bayar`, `menunggu_verifikasi`, `lunas`, `terlambat`, `dibatalkan`
- **MetodeBayar**: `transfer`, `tunai`
- **StatusPembayaran**: `menunggu`, `diterima`, `ditolak`
- **TargetPengumuman**: `semua`, `guru`, `wali_murid`, `kelas`, `murid`
- **JenisAgenda**: `kegiatan`, `libur`, `rapat`, `lainnya`
- **StatusRapor**: `draft`, `diajukan`, `revisi`, `terbit`
- **StatusPendaftaran**: `diajukan`, `diverifikasi`, `diterima`, `ditolak`
- **JenisDokumen**: `akta_kelahiran`, `kartu_keluarga`, `pas_foto`, `lainnya`

## A6. Flowchart

### Autentikasi & onboarding

```mermaid
flowchart TD
    A([Buka /login]) --> B{Jenis pengguna}
    B -->|Guru / Kepsek| C[Isi email + password]
    C --> D{Kredensial valid?}
    D -->|Tidak| C
    D -->|Ya| E{Status akun}
    E -->|pending| F[Halaman menunggu persetujuan]
    E -->|ditolak / nonaktif| G[Tampilkan pesan + alasan]
    E -->|aktif| H[Dashboard sesuai role]
    B -->|Wali Murid| I[Klik Masuk dengan Google]
    I --> J[BE verifikasi ID token Google]
    J --> K{User sudah ada?}
    K -->|Belum| L[Buat user role wali_murid status aktif + profil wali]
    K -->|Sudah| M{profil_lengkap?}
    L --> N[Onboarding: lengkapi no HP, alamat, pekerjaan]
    M -->|Tidak| N
    M -->|Ya| O{Punya anak tertaut?}
    N --> O
    O -->|Ya| H
    O -->|Tidak| P[Empty state: Tautkan anak / Daftar PPDB]
    P -->|Tautkan| Q[Input kode tautan + tanggal lahir anak]
    Q --> R{Valid & belum kedaluwarsa?}
    R -->|Ya| H
    R -->|Tidak| Q
    P -->|PPDB| S[Form pendaftaran anak baru]
    T([Guru daftar di /daftar-guru]) --> U[Akun dibuat status pending]
    U --> V[Notifikasi ke Kepsek]
    V --> W{Kepsek memutuskan}
    W -->|Setujui| X[Status aktif + notifikasi email ke guru]
    W -->|Tolak| Y[Status ditolak + alasan]
```

### Tagihan bulanan & pembayaran

```mermaid
flowchart TD
    A([Scheduler tgl 1 pukul 00:10 WIB]) --> B[Ambil tahun ajaran aktif]
    B -->|Tidak ada / bulan di luar TA aktif| B2[Notifikasi Kepsek: tagihan bulan ini belum dibuat]
    B --> C[Ambil jenis_tagihan bulanan aktif]
    C --> D[Loop murid aktif yang punya kelas di TA aktif]
    D --> E{Jenis tagihan sesuai tingkat murid?}
    E -->|Tidak| D
    E -->|Ya| F{Tagihan periode ini sudah ada?}
    F -->|Ya| D
    F -->|Tidak| G[Hitung potongan dari keringanan yang berlaku]
    G --> H[Buat tagihan status belum_bayar, jatuh tempo tgl pengaturan]
    H --> I[Notifikasi ke semua wali murid tsb]
    J([Scheduler harian 07:00 WIB]) --> K{H-N sebelum jatuh tempo?}
    K -->|Ya| L[Kirim pengingat ke wali]
    J --> M{Lewat jatuh tempo & belum_bayar?}
    M -->|Ya| N[Status terlambat + notifikasi tunggakan]
    O([Wali buka tagihan]) --> P[Lihat rekening sekolah]
    P --> Q[Upload bukti transfer]
    Q --> R[Pembayaran menunggu, tagihan menunggu_verifikasi]
    R --> S[Notifikasi ke petugas keuangan]
    S --> T{Verifikasi}
    T -->|Terima| U[Pembayaran diterima, tagihan lunas, kwitansi PDF tersedia]
    T -->|Tolak + alasan| V[Tagihan kembali ke belum_bayar / terlambat]
    V --> O
    W([Petugas catat bayar tunai / transfer]) --> U
```

### Rapor

```mermaid
flowchart TD
    A([Guru buka menu Rapor]) --> B[Pilih kelas & semester]
    B --> C[Buat / buka rapor murid: status draft]
    C --> D[Isi tinggi, berat, deskripsi tiap elemen, catatan guru]
    D --> E[Ajukan]
    E --> F[Status diajukan + notifikasi ke Kepsek]
    F --> G{Kepsek review}
    G -->|Minta revisi + catatan| H[Status revisi + notifikasi guru]
    H --> D
    G -->|Terbitkan| I[Status terbit + notifikasi ke wali]
    I --> J[Wali lihat & unduh PDF rapor]
```

### PPDB

```mermaid
flowchart TD
    A([Wali buka menu PPDB]) --> B{PPDB dibuka & kuota tersedia?}
    B -->|Tidak| C[Tampilkan info PPDB ditutup]
    B -->|Ya| D[Isi data anak & orang tua]
    D --> E[Upload akta, KK, pas foto]
    E --> F[Status diajukan + notifikasi Kepsek]
    F --> G{Kepsek cek}
    G -->|Dokumen OK| H[Status diverifikasi]
    G -->|Tolak + alasan| I[Status ditolak + notifikasi wali]
    H --> J{Keputusan akhir}
    J -->|Terima + pilih kelas opsional| K[Buat murid + tautkan ke wali + masukkan kelas]
    K --> L[Status diterima + notifikasi wali]
    J -->|Tolak| I
```

## A7. Kontrak API

**Base URL:** `{BE_URL}/api/v1`. Auth: header `Authorization: Bearer {token}` (Sanctum). Semua respons JSON.

**Format respons sukses:**
```json
{ "success": true, "message": "Berhasil", "data": {}, "meta": null }
```
**Respons list berpaginasi** (`?page=1&per_page=15`, default 15, max 100):
```json
{ "success": true, "message": "Berhasil", "data": [], "meta": { "current_page": 1, "per_page": 15, "total": 120, "last_page": 8 } }
```
**Respons error:**
```json
{ "success": false, "message": "Data tidak valid", "code": "VALIDATION_ERROR", "errors": { "email": ["Email wajib diisi."] } }
```
Kode error: `UNAUTHENTICATED` (401), `FORBIDDEN` (403), `ACCOUNT_PENDING` (403), `ACCOUNT_REJECTED` (403), `ACCOUNT_INACTIVE` (403), `NOT_FOUND` (404), `VALIDATION_ERROR` (422), `BUSINESS_RULE` (422, pelanggaran aturan bisnis), `TOO_MANY_REQUESTS` (429), `SERVER_ERROR` (500).

Untuk `ACCOUNT_REJECTED` saat login, alasan penolakan disertakan di `message` (contoh: `"Pendaftaran akun Anda ditolak. Alasan: …"`), tanpa field tambahan.

Status HTTP di luar daftar di atas dipetakan ke kode terdekat: 405 (metode HTTP salah) → 404 `NOT_FOUND`; 413 (unggahan melebihi batas server) → 422 `VALIDATION_ERROR` dengan pesan "Ukuran file terlalu besar. Maksimal 5 MB per file."; status 4xx lain → 422 `VALIDATION_ERROR`; 503 (pemeliharaan) → 503 `SERVER_ERROR`.

**Konvensi query list:** `?search=`, `?sort=nama` / `?sort=-created_at`, `?filter[status]=aktif`, `?filter[kelas_id]=3`. Tanggal format `YYYY-MM-DD`, datetime ISO 8601 dengan offset `+07:00`. Uang = integer rupiah.

**File private:** semua `*_url` untuk file private (foto kegiatan, foto murid, foto rapor, bukti bayar, dokumen PPDB) adalah signed URL `GET /media/{token}` yang **bisa langsung dipakai di `<img>` / `<a>` tanpa header Authorization** dan berlaku **30 menit**. Setelah kedaluwarsa, ambil ulang datanya untuk mendapat URL baru. Hak akses dicek saat URL dibuat, jadi URL hanya dikirim ke pengguna yang berhak; siapa pun yang memegang URL bisa membukanya selama masa berlaku.

**Singkatan role:** SA = super_admin, G = guru, K = petugas keuangan (SA atau guru `bisa_kelola_keuangan`), W = wali_murid, Pub = publik.

**Scoping data otomatis:** endpoint yang sama mengembalikan data berbeda per role. G hanya melihat kelas yang dia ampu (wali kelas / pendamping) di TA aktif. W hanya melihat anaknya sendiri. SA melihat semua.

### Publik
- `GET /public/profil` — Pub — semua pengaturan grup profil + landing
- `GET /public/pengumuman` — Pub — pengumuman `is_publik` & terbit, berpaginasi
- `GET /public/pengumuman/{slug}` — Pub
- `GET /public/agenda?bulan=YYYY-MM` — Pub — agenda `is_publik`
- `GET /public/galeri` — Pub — album publik
- `GET /public/galeri/{slug}` — Pub — album + foto
- `GET /public/guru` — Pub — guru aktif `tampil_di_landing` (nama, jabatan, foto)
- `GET /public/ppdb` — Pub — status buka, tanggal, kuota, sisa kuota, info

### Auth
- `POST /auth/login` — Pub — `{ email, password, perangkat? }` → `{ token, user }`. Rate limit 5/menit per IP+email
- `POST /auth/google` — Pub — `{ id_token, perangkat? }` → `{ token, user, is_new }`
- `perangkat`: `web` | `mobile`, opsional, default `web`; dipakai sebagai nama token Sanctum.
- `POST /auth/register-guru` — Pub — `{ name, email, password, password_confirmation, no_hp, jenis_kelamin }` → 201, pesan menunggu persetujuan
- `POST /auth/forgot-password` — Pub — `{ email }` (hanya akun email+password)
- `POST /auth/reset-password` — Pub — `{ token, email, password, password_confirmation }`
- `GET /auth/me` — semua — user + profil (guru/wali) + untuk W: daftar anak ringkas
- `POST /auth/logout` — semua
- `PUT /auth/profil` — semua — multipart (avatar opsional)
- `PUT /auth/password` — SA, G

**Bentuk `user` di respons auth:**
```json
{
  "id": 1, "name": "…", "email": "…", "role": "guru", "status": "aktif",
  "no_hp": "…", "avatar_url": "…",
  "guru": { "id": 3, "bisa_kelola_keuangan": false, "kelas_diampu": [{ "id": 2, "nama": "TK A1" }] },
  "wali_murid": null,
  "permissions": { "kelola_keuangan": false }
}
```
Untuk W: `"wali_murid": { "id": 5, "profil_lengkap": true, "anak": [{ "id": 9, "nama_panggilan": "…", "kelas": "TK B2", "foto_url": "…" }] }`.

### Dashboard
- `GET /dashboard` — semua — payload sesuai role; W bisa kirim `?murid_id=`
  - SA: `{ statistik: { murid_aktif, guru_aktif, kelas, wali_murid }, keuangan_bulan_ini: { total_tagihan, terbayar, belum_terbayar, persen_lunas }, grafik_pemasukan: [{ bulan: "2026-01", total }] (12 bulan), tertunda: { guru_pending, pembayaran_menunggu, rapor_diajukan, pendaftaran_baru }, pengumuman_terbaru[], agenda_mendatang[] }`. `guru_aktif` tidak menghitung profil guru milik Kepala Sekolah.
  - G: `{ kelas_saya[] (id, nama, jumlah_murid), progres_rapor: { total, draft, diajukan, revisi, terbit }, kegiatan_terbaru[], pengumuman_terbaru[], agenda_mendatang[], keuangan_kelas: { lunas, belum }, pembayaran_menunggu }`. `pembayaran_menunggu` = jumlah pembayaran berstatus `menunggu` (int) untuk guru `bisa_kelola_keuangan`, `null` untuk guru lain.
  - W: `{ anak: {…}, tagihan_aktif[] , total_belum_bayar, kegiatan_terbaru[], pengumuman_terbaru[], agenda_mendatang[], rapor_terbaru }`

### Guru (manajemen)
- `GET /guru` — SA — filter status, search. Tidak termasuk profil guru milik Kepala Sekolah
- `GET /guru/{id}` — SA — termasuk profil guru milik Kepala Sekolah
- `POST /guru` — SA — buat akun guru langsung aktif → 201, data guru + `password_awal`. `password_awal` hanya muncul di respons ini, tidak dikirim lewat email, dan tidak disimpan sebagai teks biasa
- `PUT /guru/{id}` — SA — termasuk `bisa_kelola_keuangan`, `tampil_di_landing`. Untuk profil guru milik Kepala Sekolah, mengubah `bisa_kelola_keuangan` ditolak (422 `BUSINESS_RULE`)
- `POST /guru/{id}/setujui` — SA
- `POST /guru/{id}/tolak` — SA — `{ alasan }`
- `PATCH /guru/{id}/status` — SA — `{ status: aktif|nonaktif }` (nonaktif = cabut semua token). Ditolak untuk profil guru milik Kepala Sekolah (422 `BUSINESS_RULE`)

### Tahun ajaran & kelas
- `GET|POST /tahun-ajaran`, `PUT|DELETE /tahun-ajaran/{id}` — SA (GET: SA, G)
- `POST /tahun-ajaran/{id}/aktifkan` — SA — menonaktifkan yang lain
- `GET /kelas?filter[tahun_ajaran_id]=` — SA, G(scoped)
- `POST /kelas`, `PUT|DELETE /kelas/{id}` — SA
- `GET /kelas/{id}` — SA, G(scoped) — detail + murid
- `POST /kelas/{id}/murid` — SA — `{ murid_ids: [] }` (cek kapasitas & 1 kelas per TA)
- `DELETE /kelas/{id}/murid/{murid_id}` — SA
- `POST /kelas/kenaikan` — SA — `{ tahun_ajaran_tujuan_id, penempatan: [{ murid_id, kelas_tujuan_id | null, status: naik|tinggal|lulus }] }`

### Murid & wali
- `GET /murid` — SA, G(scoped), W(anak sendiri) — filter kelas_id, status, tingkat, search
- `GET /murid/{id}` — SA, G(scoped), W(anak sendiri) — detail + kelas aktif + wali
- `POST /murid`, `PUT /murid/{id}`, `DELETE /murid/{id}` — SA (multipart foto)
- `POST /murid/{id}/kode-tautan` — SA — generate baru → `{ kode, expired_at }`
- `DELETE /murid/{id}/wali/{wali_murid_id}` — SA — lepas tautan
- `GET /wali-murid` — SA — search, dengan jumlah anak
- `GET /wali-murid/{id}` — SA
- `PATCH /wali-murid/{id}/status` — SA — aktif / nonaktif
- `PUT /wali/profil` — W — onboarding `{ no_hp, alamat, pekerjaan, nik? }` → set `profil_lengkap`
- `POST /wali/tautkan-anak` — W — `{ kode, tanggal_lahir, hubungan }`. Rate limit 5/menit
- `GET /wali/anak` — W

### Keuangan
- `GET|POST /jenis-tagihan`, `PUT|DELETE /jenis-tagihan/{id}` — SA (GET: K)
- `GET|POST /keringanan`, `PUT|DELETE /keringanan/{id}` — K
- `GET /tagihan` — K, G(scoped, read-only), W(anak sendiri) — filter status, periode (YYYY-MM), kelas_id, murid_id, jenis_tagihan_id
- `GET /tagihan/{id}` — K, G(scoped), W(anak sendiri) — termasuk riwayat pembayaran + rekening sekolah
- `POST /tagihan` — K — tagihan sekali: `{ jenis_tagihan_id, murid_ids?: [], kelas_id?: , jatuh_tempo }` → `{ dibuat, dilewati }`. Murid yang sudah punya tagihan jenis itu (selain `dibatalkan`) dilewati
- `POST /tagihan/generate` — SA — `{ periode: "YYYY-MM" }` → `{ dibuat, dilewati }` (idempoten)
- `PATCH /tagihan/{id}/batalkan` — SA — `{ alasan }`
- `POST /tagihan/{id}/pembayaran` — W (multipart: bukti wajib, tanggal_bayar, bank_pengirim, nama_pengirim) / K (`metode` tunai atau transfer, tanggal_bayar → langsung diterima; untuk transfer bukti, bank_pengirim, nama_pengirim opsional)
- `GET /pembayaran` — K, W(sendiri) — filter status, metode, tanggal
- `GET /pembayaran/{id}` — K, W(sendiri)
- `POST /pembayaran/{id}/terima` — K
- `POST /pembayaran/{id}/tolak` — K — `{ alasan }`
- `GET /pembayaran/{id}/bukti` — K, W(sendiri) — file (stream)
- `GET /pembayaran/{id}/kwitansi` — K, W(sendiri) — PDF (hanya status diterima)
- `GET /laporan/keuangan?dari=&sampai=&kelas_id=` — K — ringkasan + per jenis + per bulan
- `GET /laporan/keuangan/export?dari=&sampai=` — K — file .xlsx
- `GET /laporan/tunggakan?kelas_id=` — K — daftar murid menunggak + total

### Akademik & komunikasi
- `GET /kegiatan?filter[kelas_id]=` — SA, G(scoped), W(kelas anak)
- `GET /kegiatan/{id}` — sama
- `POST /kegiatan` — G(kelas sendiri), SA — multipart, `foto[]` maks 10
- `PUT|DELETE /kegiatan/{id}` — pembuat, SA
- `POST /kegiatan/{id}/foto`, `DELETE /kegiatan-foto/{id}` — pembuat, SA
- `GET /media/{token}` — tanpa token Bearer (signed URL) — stream file private untuk semua `*_url` private; hanya memvalidasi signature, masa berlaku 30 menit, dan token. Lihat "File private" di atas
- `GET /elemen-penilaian` — SA, G. `POST|PUT|DELETE` — SA
- `GET /rapor?filter[kelas_id]=&filter[semester]=&filter[status]=` — SA, G(scoped), W(anak, hanya terbit)
- `GET /rapor/{id}` — sama
- `POST /rapor` — G, SA (hanya murid di kelas yang diampu sebagai wali kelas / pendamping di TA aktif) — `{ murid_id, semester }` → buat draft dengan baris detail kosong per elemen aktif
- `PUT /rapor/{id}` — G(pembuat, hanya status draft/revisi) — `{ tinggi_badan, berat_badan, catatan_guru, detail: [{ elemen_penilaian_id, deskripsi }] }`
- `POST /rapor/{id}/detail/{detail_id}/foto` — G(pembuat)
- `POST /rapor/{id}/ajukan` — G
- `POST /rapor/{id}/terbitkan` — SA
- `POST /rapor/{id}/revisi` — SA — `{ catatan }`
- `GET /rapor/{id}/pdf` — SA, G(scoped), W(hanya terbit)
- `GET /pengumuman` — semua — feed relevan untuk user (SA: semua)
- `GET /pengumuman/{id}` — sama
- `POST /pengumuman` — SA (target apa pun), G (target `kelas` yang diampu / `murid` di kelasnya) — `{ judul, isi, target, kelas_ids?, murid_ids?, is_publik?, is_pinned?, publish: bool }`
- `PUT|DELETE /pengumuman/{id}` — penulis, SA
- `GET /agenda?bulan=YYYY-MM` — semua. `POST|PUT|DELETE` — SA
- `GET /notifikasi` — semua. `GET /notifikasi/belum-dibaca` → `{ jumlah }`. `POST /notifikasi/{id}/baca`. `POST /notifikasi/baca-semua`

**Bentuk notifikasi:** `{ id, jenis, judul, pesan, url (path FE tujuan, misal "/dashboard/tagihan/12"), dibaca_at, created_at }`. Jenis: `tagihan_baru`, `tagihan_tertunda` (ke Kepsek: generate terjadwal dilewati karena bulan di luar tahun ajaran aktif), `pengingat_tagihan`, `tagihan_terlambat`, `pembayaran_masuk`, `pembayaran_diterima`, `pembayaran_ditolak`, `guru_baru`, `rapor_diajukan`, `rapor_revisi`, `rapor_terbit`, `pengumuman_baru`, `pendaftaran_baru`, `pendaftaran_diproses`, `anak_tertaut`.

### PPDB
- `POST /pendaftaran` — W — multipart (data + `hubungan` + dokumen). Tahun ajaran diambil dari `ppdb.tahun_ajaran_id`. Tolak jika PPDB tutup / kuota penuh / NIK anak sudah punya pendaftaran selain `ditolak` atau sudah menjadi murid (pendaftar yang pernah ditolak boleh daftar ulang)
- `GET /pendaftaran` — SA (semua), W (miliknya)
- `GET /pendaftaran/{id}` — SA, W(miliknya)
- `POST /pendaftaran/{id}/verifikasi` — SA
- `POST /pendaftaran/{id}/terima` — SA — `{ kelas_id? }`
- `POST /pendaftaran/{id}/tolak` — SA — `{ alasan }`

### CMS & pengaturan
- `GET /pengaturan?grup=` — SA (K boleh baca grup keuangan)
- `PUT /pengaturan` — SA — `{ items: { "profil.visi": "…", "landing.program": [ … ] } }` (validasi per kunci). `ppdb.dibuka = true` ditolak kalau `ppdb.tahun_ajaran_id` kosong atau tahun ajarannya tidak ada
- `POST /pengaturan/upload` — SA — gambar → `{ path, url }`
- Field gambar di pengaturan disimpan sebagai path. Di respons `GET /pengaturan` dan `GET /public/profil`, setiap field gambar mendapat pasangan `*_url`: kunci `profil.logo` disertai kunci `profil.logo_url`; `landing.hero` → `{ judul, subjudul, gambar, gambar_url, cta_teks }`; `landing.fasilitas[]` → `{ nama, deskripsi, gambar, gambar_url }`. Saat `PUT /pengaturan`, field `*_url` diabaikan.
- `GET|POST /galeri-album`, `PUT|DELETE /galeri-album/{id}` — SA. `GET /galeri-album` berisi `cover_url` dan `jumlah_foto` tanpa daftar foto
- `GET /galeri-album/{id}` — SA — album + semua foto (termasuk album yang belum publik)
- `POST /galeri-album/{id}/foto` — SA — `foto[]`
- `PUT|DELETE /galeri-foto/{id}` — SA
- `GET /log-aktivitas` — SA — filter user, jenis, tanggal

---

# BAGIAN B — STANDAR TEKNIS BACKEND

## B1. Stack & paket

Pakai **versi stabil terbaru** saat pengerjaan dan cek kompatibilitas tiap paket dengan versi Laravel tersebut di Fase 0. Kalau ada paket yang belum kompatibel, usulkan alternatif sebelum memasang.

| Kebutuhan | Paket / pilihan |
|---|---|
| Framework | Laravel (terbaru), PHP 8.4+ (dibutuhkan `spatie/laravel-activitylog` v5 dan Pest v5) |
| Database | MySQL 8 (utf8mb4). Test pakai SQLite in-memory atau MySQL test DB, pilih yang tidak bentrok dengan fitur yang dipakai |
| Auth token | `laravel/sanctum` (Bearer token, bukan cookie SPA, karena dipakai web + Flutter) |
| Dokumentasi API | `dedoc/scramble` — UI di `/docs/api`, export spec ke `storage/api-docs/api.json` |
| Login Google | `google/apiclient` untuk `verifyIdToken` (FE kirim ID token dari Google Identity Services, BE yang memverifikasi, cek `aud` = `GOOGLE_CLIENT_ID` dan `email_verified`) |
| Filter/sort/include list | `spatie/laravel-query-builder` |
| Audit log | `spatie/laravel-activitylog` |
| PDF (rapor, kwitansi) | `barryvdh/laravel-dompdf` |
| Export Excel | `maatwebsite/excel` (alternatif `openspout/openspout` jika belum kompatibel) |
| Kompres/resize gambar | `intervention/image` (v4) — foto maks lebar 1600px, kualitas 80, format jpg/webp |
| Sanitasi HTML (pengumuman, CMS) | `stevebauman/purify` atau `mews/purifier` |
| Testing | Pest |
| Kualitas kode | Laravel Pint, Larastan (level 6 minimal) |
| Queue | driver `database` (notifikasi & email lewat queue) |
| Mail dev | log driver atau Mailpit |

**Tidak pakai** `spatie/laravel-permission`: role cukup kolom enum + Policy/Gate, karena hanya 3 role dan 1 izin tambahan (`bisa_kelola_keuangan`).

## B2. Arsitektur & struktur folder

```
app/
  Enums/                  # PHP backed enum untuk semua enum di A5 (dengan method label())
  Http/
    Controllers/Api/V1/   # controller tipis, dikelompokkan per modul
    Requests/             # FormRequest per aksi (validasi + authorize dasar)
    Resources/            # JsonResource per entitas (bentuk data konsisten)
    Middleware/           # EnsureAccountActive, EnsureRole, ForceJsonResponse
  Models/
  Policies/               # otorisasi & scoping per model
  Services/               # logika bisnis (TagihanService, PembayaranService, RaporService, PendaftaranService, KodeTautanService, MediaService, PengaturanService, DashboardService)
  Notifications/
  Console/Commands/       # tagihan:generate, tagihan:pengingat, tagihan:tandai-terlambat
  Support/ApiResponse.php # helper format respons A7
  Exports/                # export Excel
resources/views/pdf/      # template rapor & kwitansi (hijau, logo sekolah)
routes/api.php            # prefix v1, dikelompokkan per modul
```

Aturan:
- Controller hanya: terima FormRequest → panggil Service/Model → kembalikan Resource via `ApiResponse`. Tidak ada logika bisnis di controller.
- Operasi multi-tabel (terima pembayaran, terima PPDB, kenaikan kelas, generate tagihan) wajib di dalam `DB::transaction`.
- Hindari N+1: eager load di query list, aktifkan `Model::preventLazyLoading()` di non-production.
- Nama tabel bahasa Indonesia: set `protected $table` di model. Pivot pakai model `Pivot` bila punya kolom tambahan.
- Semua enum di-cast ke PHP enum di model.
- Index database untuk kolom yang sering difilter: `status`, `periode`, `jatuh_tempo`, FK, `published_at`.

## B3. Format respons & error

- Buat `ApiResponse::success($data, $message, $meta)`, `ApiResponse::paginated($paginator, ResourceClass)`, `ApiResponse::error($message, $code, $status, $errors)` sesuai A7.
- Tangani semua exception di `bootstrap/app.php` (`withExceptions`) supaya **semua** error (validasi, 401, 403, 404, 429, ModelNotFound, error bisnis) keluar dengan format A7. Buat `BusinessRuleException` untuk pelanggaran aturan bisnis → 422 `BUSINESS_RULE`.
- Pesan validasi Bahasa Indonesia: pasang file lang `id` (validation.php) dan set `APP_LOCALE=id`, `APP_FAKER_LOCALE=id_ID`, timezone `Asia/Jakarta`.
- Pastikan Scramble tetap bisa membaca bentuk respons: pakai return type eksplisit dan Resource, tambahkan anotasi (`@response`, `@status`) di tempat yang inferensinya meleset. Cek manual di `/docs/api` tiap fase.

## B4. Otorisasi & scoping

- Middleware `EnsureAccountActive`: token milik user berstatus selain `aktif` → 403 dengan kode `ACCOUNT_PENDING` / `ACCOUNT_REJECTED` / `ACCOUNT_INACTIVE`. Menonaktifkan user wajib menghapus semua tokennya.
- Middleware `role:super_admin,guru` untuk pembatasan kasar per route; detail per data di Policy.
- Gate `kelola-keuangan`: `super_admin` ATAU guru dengan `bisa_kelola_keuangan = true`.
- **Scoping** (buat sebagai query scope, dipakai di semua endpoint terkait):
  - `Murid::scopeVisibleTo(User $user)`: SA semua; guru hanya murid di kelas yang dia ampu (wali kelas/pendamping) di TA aktif; wali hanya anaknya.
  - Hal serupa untuk `Tagihan`, `Pembayaran`, `KegiatanKelas`, `Rapor`, `Pengumuman` (feed), `Pendaftaran`.
- Akses data milik orang lain → **404** (bukan 403) supaya tidak membocorkan keberadaan data.
- `catatan_khusus` murid hanya muncul di Resource untuk SA, guru pengampu, dan wali anak tsb.
- Wali tidak boleh melihat rapor berstatus selain `terbit`.

## B5. File & media

- Disk `public`: logo, aset landing, galeri, avatar, foto guru untuk landing.
- Disk `local` (private): foto kegiatan, bukti bayar, dokumen PPDB, foto rapor, foto murid.
- Resource mengembalikan `*_url`. Untuk file private, URL berupa **signed temporary route** (`GET /api/v1/media/{token}`, berlaku 30 menit) yang dilayani `MediaService`. Hak akses dicek saat URL dibuat: Resource hanya membuat URL untuk pengguna yang berhak melihat file itu. Route media tidak memakai `auth:sanctum` karena URL dipakai langsung di `<img>` / `<a>` oleh browser tanpa header Authorization; route hanya memvalidasi signature, masa berlaku, dan token. Token berisi path terenkripsi, bukan path mentah.
- Validasi upload: gambar `jpg,jpeg,png,webp` maks 5 MB; dokumen PPDB juga boleh `pdf` maks 5 MB. Gambar di-resize/kompres sebelum disimpan. Nama file acak (UUID).
- Hapus file fisik saat record dihapus (observer / event).

## B6. Aturan bisnis kunci (wajib ada test-nya)

1. **Generate tagihan** (`TagihanService::generateBulanan(Carbon $periode)`): sesuai flowchart A6. Idempoten via unique index + `firstOrCreate`/`insertOrIgnore`. Kode tagihan unik berurutan per bulan. Potongan keringanan dihitung: persen → `floor(nominal * nilai / 100)`, nominal → min(nilai, nominal). `total = nominal - potongan`. Jika total 0 → langsung `lunas`. Mengembalikan jumlah dibuat & dilewati. Tagihan sekali (`POST /tagihan`) hanya untuk jenis berperiode `sekali`; karena `periode` null tidak dijaga unique index, service melewati murid yang sudah punya tagihan jenis itu (selain `dibatalkan`) dan mengembalikan `{ dibuat, dilewati }`.
2. **Scheduler** (`routes/console.php`):
   - `tagihan:generate` tiap tanggal 1 pukul 00:10 WIB. Kalau bulan berjalan di luar tahun ajaran aktif (atau belum ada tahun ajaran aktif), tidak ada tagihan dibuat dan Kepala Sekolah mendapat notifikasi `tagihan_tertunda`
   - `tagihan:tandai-terlambat` harian 00:30 WIB (tagihan `belum_bayar` yang lewat jatuh tempo → `terlambat` + notifikasi)
   - `tagihan:pengingat` harian 07:00 WIB (H-`keuangan.hari_pengingat`)
   - `kode-tautan:bersihkan` harian (kosongkan kode kedaluwarsa)
   - Semua command bisa dijalankan manual dengan opsi `--periode=YYYY-MM` / `--dry-run` untuk testing.
3. **Pembayaran**: wali selalu transfer dengan bukti wajib (status `menunggu`); petugas keuangan mencatat tunai atau transfer (bukti opsional) yang langsung `diterima`. Tolak upload baru jika sudah ada pembayaran `menunggu` atau tagihan sudah `lunas` / `dibatalkan`. Jumlah pembayaran harus sama dengan `total` tagihan. Terima → tagihan `lunas` + `lunas_at` + notifikasi wali. Tolak → tagihan kembali `terlambat` jika lewat jatuh tempo, selain itu `belum_bayar`. Semua verifikasi dicatat di activity log.
4. **Tahun ajaran**: tepat 1 yang aktif. Tidak bisa menghapus TA yang sudah punya kelas/tagihan.
5. **Kelas**: 1 murid maksimal 1 kelas per TA; tolak penempatan jika melebihi kapasitas. Mengeluarkan murid dari kelas ditolak jika murid sudah punya rapor di kelas itu. Kenaikan kelas massal dalam 1 transaksi (update `kelas_murid.status` lama, buat penempatan baru; `lulus` → `murid.status = lulus`).
6. **Kode tautan**: 8 karakter huruf besar + angka tanpa karakter ambigu (`0 O 1 I L`), unik, berlaku 14 hari. Tautan valid hanya jika kode cocok + belum kedaluwarsa + `tanggal_lahir` cocok. Gagal 5x/menit → 429. Tolak jika wali sudah tertaut ke murid tsb.
7. **Guru**: login ditolak saat `pending`/`ditolak`/`nonaktif` dengan kode error yang sesuai (FE mengarahkan ke halaman yang tepat); untuk `ditolak`, alasan penolakan masuk ke `message`. Setujui/tolak → notifikasi email ke guru. Guru daftar → notifikasi ke SA. `POST /guru` membuat password acak dan mengembalikannya sekali sebagai `password_awal`. Profil guru milik Kepala Sekolah tidak muncul di `GET /guru`, tetapi bisa dibuka dan diubah lewat `GET/PUT /guru/{id}`; menonaktifkan profil itu atau mengubah `bisa_kelola_keuangan`-nya ditolak dengan `BUSINESS_RULE`.
8. **Google login**: hanya untuk wali murid. Jika email Google sudah terdaftar sebagai guru/SA → tolak dengan pesan "Gunakan login email & password". User baru → buat `users` (role wali_murid, status aktif, email_verified_at terisi) + `wali_murid`, `is_new: true`.
9. **Rapor**: transisi status hanya sesuai flowchart; guru hanya bisa edit saat `draft`/`revisi`; `POST /rapor` otomatis membuat baris `rapor_detail` untuk semua elemen aktif. Terbit → notifikasi semua wali anak tsb.
10. **Pengumuman**: guru hanya boleh target `kelas` (kelas yang diampu) atau `murid` (murid di kelasnya). `is_publik` hanya untuk target `semua`. Saat terbit → notifikasi ke penerima sesuai target (via queue, chunk). Feed `GET /pengumuman` untuk user: target semua + target sesuai role + kelas anak/kelas diampu + murid anaknya.
11. **PPDB**: tolak jika `ppdb.dibuka = false`, di luar tanggal, kuota penuh (hitung pendaftaran selain `ditolak` untuk tahun ajaran `ppdb.tahun_ajaran_id`), atau NIK anak sudah punya pendaftaran selain `ditolak` atau sudah dipakai murid. `pendaftaran.tahun_ajaran_id` diisi dari `ppdb.tahun_ajaran_id`, `pendaftaran.hubungan` dari input wali. Terima → buat murid (NIS otomatis), tautkan wali pendaftar dengan `pendaftaran.hubungan`, masukkan kelas jika dipilih, salin pas foto jadi `foto_path` — satu transaksi.
12. **Pengaturan**: `PengaturanService` dengan cache (invalidate saat update); validasi tipe per kunci (buat aturan validasi per kunci di satu tempat). `ppdb.dibuka = true` ditolak kalau `ppdb.tahun_ajaran_id` kosong atau tahun ajarannya tidak ada. Respons menambahkan pasangan `*_url` untuk field gambar (lihat A7 CMS & pengaturan).
13. **Dashboard**: `DashboardService` per role, sesuai payload di A7. Grafik pemasukan 12 bulan terakhir dari pembayaran `diterima`. `guru_aktif` tidak menghitung profil guru Kepala Sekolah; payload G berisi `pembayaran_menunggu` (int untuk guru `bisa_kelola_keuangan`, `null` untuk guru lain).

## B7. Keamanan

- Rate limit: login 5/menit, google 10/menit, tautkan anak 5/menit, API umum 120/menit per user.
- CORS hanya `FRONTEND_URL` (env), izinkan header Authorization. Tidak pakai cookie.
- Password minimal 8 karakter, `Password::defaults()` dengan huruf + angka.
- Token Sanctum dengan nama perangkat dari field `perangkat` di login (`web` / `mobile`, default `web`), expired 30 hari.
- Semua HTML dari user disanitasi sebelum disimpan.
- Activity log untuk: approval guru, perubahan status akun, verifikasi/penolakan pembayaran, pembatalan tagihan, generate tagihan, terbit/revisi rapor, keputusan PPDB, perubahan pengaturan, tautkan/lepas wali.

## B8. Seeder

- `SuperAdminSeeder`: dari env `SUPERADMIN_NAME`, `SUPERADMIN_EMAIL`, `SUPERADMIN_PASSWORD`. Sekaligus membuat profil `guru` milik Kepala Sekolah (jabatan "Kepala Sekolah").
- `ElemenPenilaianSeeder`, `PengaturanSeeder` (nilai default A4, isi profil & landing dengan konten contoh yang wajar untuk TK).
- `DemoSeeder` (hanya dijalankan manual/di local): 1 TA aktif 2026/2027, 4 kelas (TK A1, A2, B1, B2), 6 guru aktif + 2 pending (1 guru `bisa_kelola_keuangan`), 60 murid, ±45 wali (ada yang punya 2 anak, ada anak dengan ayah & ibu), jenis tagihan SPP bulanan + uang kegiatan, tagihan 3 bulan terakhir dengan campuran status, beberapa pembayaran menunggu, kegiatan dengan foto placeholder, rapor berbagai status, pengumuman, agenda, 5 pendaftar PPDB, 2 album galeri.
- Tulis akun demo di `dokumentasi.md`.

## B9. Integrasi dengan frontend

- Export spec OpenAPI: `php artisan scramble:export --path=storage/api-docs/api.json` setiap selesai fase yang mengubah API. FE akan generate type TypeScript dari file ini, jadi nama field harus sama dengan A7.
- Sediakan `GET /api/v1/health` → `{ status: "ok", time }`.

---

# BAGIAN C — ANTI AI-SLOP (WAJIB, DICEK TIAP AKHIR FASE)

"AI-slop" = hasil yang kelihatan jelas dibuat AI tanpa dipikir: generik, berlebihan, penuh basa-basi, atau mengarang. Proyek ini harus terlihat dan terbaca seperti dikerjakan developer yang paham konteks TK ini. Aturan di bawah bersifat **larangan konkret**, bukan saran.

## C1. Kode (berlaku untuk semua file)

- **Komentar hanya untuk "kenapa"**, bukan "apa". Dilarang komentar yang mengulang kode (`// ambil data murid` di atas `$murid = Murid::find()`), komentar banner/pembatas (`// ===== SECTION =====`), dan komentar penjelasan tutorial.
- **Dilarang** kode mati, kode yang di-comment, import tidak terpakai, `console.log` / `dd()` / `dump()` / `var_dump` tertinggal, `TODO` tanpa tiket/alasan.
- **Dilarang emoji** di kode, log, pesan commit, nama file, dan pesan error.
- **Jangan over-engineering (YAGNI):** tidak ada Repository pattern di atas Eloquent, tidak ada interface dengan satu implementasi, tidak ada `BaseService`/`BaseController` generik, tidak ada factory/strategy untuk satu kasus, tidak ada komponen wrapper yang cuma meneruskan props. Abstraksi baru boleh dibuat kalau pola yang sama sudah muncul **3 kali**.
- **Jangan menambah fitur, config, atau paket di luar desain** (dark mode, i18n multi-bahasa, feature flag, analytics, PWA, websocket, dsb). Kalau menurutmu perlu, usulkan dulu.
- **Jangan menelan error:** tidak ada `try/catch` kosong, tidak ada `catch` yang mengembalikan sukses palsu atau pesan generik tanpa log.
- **Jangan mengarang API/paket.** Sebelum memakai method atau opsi sebuah library, pastikan ada di versi yang terpasang (baca dokumentasi / source di `vendor` / `node_modules`). Kalau ragu, bilang.
- **Tidak ada data hardcode di komponen/controller** kalau datanya ada di API/database/pengaturan. Angka ajaib pindahkan ke konstanta/config dengan nama yang jelas.
- **Penamaan jelas dan konsisten dengan glosarium (C4).** Dilarang `data2`, `temp`, `handleClick2`, `newFunction`, `res`, `obj`, file keranjang sampah (`utils.ts` / `Helper.php` berisi puluhan fungsi campur aduk) — kelompokkan per domain.
- **Ukuran wajar:** komponen React > ±250 baris atau method > ±40 baris dipecah berdasarkan tanggung jawab, bukan dipotong asal.
- **Jangan membungkam checker:** dilarang `@ts-ignore`, `@ts-expect-error`, `eslint-disable`, `@phpstan-ignore`, non-null assertion `!` kecuali ada komentar alasan yang masuk akal.
- **Konsisten:** satu cara untuk satu hal (satu pola fetch data, satu pola form, satu pola response). Jangan campur gaya di file berbeda.

## C2. Test

- Test menguji **perilaku** (input → output/efek), bukan detail implementasi.
- Dilarang test kosong, `assertTrue(true)`, test yang me-mock hal yang sedang diuji, atau snapshot massal tanpa makna.
- Nama test menjelaskan skenario dalam kalimat (`wali tidak bisa melihat tagihan anak orang lain`).
- Test harus benar-benar dijalankan. Jangan melaporkan "semua test lulus" tanpa menjalankannya.

## C3. Teks UI, konten, dan pesan

- **Bahasa Indonesia yang wajar, spesifik, dan singkat.** Tulis seperti staf TU yang ramah, bukan brosur marketing.
- **Kata/frasa terlarang:** "seamless", "revolusioner", "solusi terdepan/terbaik", "era digital", "transformasi digital", "memberdayakan", "tingkatkan pengalaman Anda", "platform all-in-one", "mudah, cepat, dan aman", "#1", "canggih", "inovatif", "Selamat datang di masa depan…", "Mari bersama…". Juga hindari pola tiga kata sifat berjejer.
- **Dilarang emoji dan tanda seru berlebihan** di UI. Maksimal satu tanda seru untuk pesan sukses yang memang perlu.
- **Label tombol = kata kerja yang spesifik:** "Unggah Bukti Transfer", "Setujui Guru", "Terbitkan Rapor" — bukan "Submit", "Kirim Sekarang!", "Lanjutkan" di mana-mana.
- **Pesan error** menjelaskan apa yang terjadi + apa yang bisa dilakukan ("Kode tautan sudah kedaluwarsa. Minta kode baru ke pihak sekolah."), bukan "Terjadi kesalahan".
- **Empty state** memberi arah tindakan yang nyata ("Belum ada tagihan bulan ini."), bukan kalimat puitis.
- **Dilarang data palsu yang tampil ke publik:** tidak ada statistik karangan ("1000+ siswa bahagia"), testimoni fiktif, rating bintang, atau logo mitra palsu. Semua konten landing berasal dari CMS / API.
- **Data seed/mock realistis Indonesia:** nama anak & orang tua Indonesia yang wajar, alamat Semarang, nomor HP format `08xx`. Dilarang "John Doe", "Test User", "Lorem ipsum", "asdf".

## C4. Glosarium istilah (pakai persis, di kode dan UI)

| Istilah | Jangan diganti dengan |
|---|---|
| Kepala Sekolah | Admin utama, Principal, Kepsek (di UI) |
| Guru | Pengajar, Staff, Teacher |
| Wali Murid | Parent, User (di UI). "Orang Tua / Wali" hanya di tab login |
| Murid | Siswa, Peserta didik, Student (pilih satu: **Murid**) |
| Tagihan | Invoice, Bill |
| Pembayaran | Transaksi, Payment |
| Kegiatan Kelas | Aktivitas, Jurnal, Post |
| Rapor | Laporan perkembangan, Report card |
| Pengumuman | Info, Berita, Broadcast |
| Kode Tautan | Kode undangan, Token, Invite code |
| Tahun Ajaran | Periode, Academic year |

## C5. Dokumentasi, commit, dan laporan fase

- `dokumentasi.md` & README **faktual dan padat**: tanpa kalimat pembuka basa-basi, tanpa emoji di heading ("🚀 Fitur Utama"), tanpa mengklaim fitur yang belum dibuat. Tulis yang benar-benar ada dan cara menjalankannya.
- **Commit** kecil per fitur, format Conventional Commits (`feat(tagihan): generate tagihan bulanan otomatis`), jelaskan apa & kenapa. Dilarang "update files", "fix", "wip", atau commit raksasa satu fase sekaligus.
- **Laporan akhir fase jujur:** sebutkan apa yang sudah diuji dan bagaimana, apa yang gagal, apa yang belum terverifikasi, dan asumsi yang kamu ambil. Dilarang "semua berjalan sempurna" / "production-ready" tanpa bukti.

## C6. Cek otomatis sebelum lapor fase

Jalankan pencarian ini dan bersihkan hasilnya (atau jelaskan kenapa sah):
- Emoji di source: cari karakter emoji di folder source.
- Sisa debug: `console.log`, `debugger`, `dd(`, `dump(`, `var_dump`, `ray(`.
- Placeholder: `TODO`, `FIXME`, `lorem`, `ipsum`, `John Doe`, `example.com` (kecuali di test).
- Pembungkam checker: `@ts-ignore`, `eslint-disable`, `@phpstan-ignore`, `as any`, `: any`.
- Kata terlarang C3 di file UI/konten/seed.

Buat perintah ini sebagai script (`npm run check:slop` di FE / `composer check:slop` di BE) supaya bisa dijalankan ulang.

## C7. Khusus backend

- Controller tipis (sesuai B2); jangan pindahkan semua logika ke satu `GodService` raksasa. Satu service per domain.
- Resource hanya mengeluarkan field yang ada di A7. Jangan menambah field "biar lengkap" dan jangan mengembalikan model mentah.
- Jangan membuat endpoint yang tidak ada di A7. Kalau butuh endpoint baru, usulkan dulu beserta alasannya.
- Migration rapi: satu migration per tabel (plus migration terpisah untuk perubahan), nama kolom persis desain, tidak ada kolom "jaga-jaga".
- Template PDF (rapor & kwitansi) sederhana dan resmi: kop sekolah dari pengaturan, tabel rapi, tanpa ornamen berlebihan.
- Pesan notifikasi & email singkat dan spesifik ("Tagihan SPP Oktober 2026 untuk Aisyah sebesar Rp 150.000 jatuh tempo 10 Oktober."), bukan template generik.

---

# BAGIAN D — FASE PENGERJAAN

| Fase | Isi | Selesai jika |
|---|---|---|
| **0. Analisis** | Baca semua, cek versi & kompatibilitas paket, susun rencana migration & folder, daftar pertanyaan. **Tanpa kode.** | Rencana disetujui |
| **1. Fondasi** | Install Laravel + paket, config (timezone, locale id, lang validasi), `ApiResponse`, exception handler, middleware, semua Enum, Scramble (`/docs/api` + auth Bearer), CORS, Pint/Larastan/Pest, `/health`, `dokumentasi.md` | `/docs/api` terbuka, test health lulus |
| **2. Database** | Semua migration, model + relasi + cast + scope, factory, seeder (termasuk DemoSeeder) | `migrate:fresh --seed` sukses, test relasi dasar lulus |
| **3. Auth & akun** | Login, Google, register guru, lupa/reset password, me, profil, password, manajemen guru (CRUD, approval, status, izin keuangan), onboarding wali, tautkan anak, manajemen wali murid, policies dasar | Test: semua jalur login & status akun, approval guru, tautkan anak (sukses, kode salah, kedaluwarsa, tanggal lahir salah, rate limit) |
| **4. Master akademik** | Tahun ajaran, kelas, penempatan murid, kenaikan massal, murid (CRUD, foto, kode tautan, lepas wali), media signed URL | Test scoping guru/wali, kapasitas kelas, 1 kelas per TA |
| **5. Keuangan** | Jenis tagihan, keringanan, generate (service + command + scheduler), tagihan sekali, pembayaran (upload, tunai, terima, tolak), bukti, kwitansi PDF, laporan, export Excel, tunggakan, pengingat & terlambat | Test: generate idempoten + keringanan, alur pembayaran lengkap, izin keuangan guru |
| **6. Akademik & komunikasi** | Kegiatan + foto, elemen penilaian, rapor (alur status + PDF), pengumuman + targeting + feed, agenda, notifikasi (endpoint + semua jenis A7) | Test alur rapor, feed pengumuman per role, guru tidak bisa target kelas lain |
| **7. PPDB, CMS, dashboard** | Pendaftaran (alur lengkap), pengaturan + upload, galeri, semua endpoint `/public/*`, `GET /dashboard` per role, log aktivitas | Test PPDB terima → murid + tautan, kuota, dashboard per role |
| **8. Hardening** | Rate limit, audit N+1, index, cek ulang semua bentuk respons di `/docs/api`, export `api.json`, lengkapi `dokumentasi.md` & README | Semua test, Pint, Larastan hijau; `api.json` ter-update |

Mulai dari **Fase 0** sekarang.
