# Kontrak API: hak akses guru dan status murid

Kontrak untuk dua fitur di branch `be/hak-akses-status-murid`: izin modul guru dari Kepala Sekolah (A2.13) dan status murid, alumni, serta import massal (A2.14). Semua endpoint memakai prefix `/api/v1`, header `Authorization: Bearer`, dan format respons A7 (`{ success, message, data, meta }`, error `{ success: false, message, code, errors }`). Tipe lengkap ada di `storage/api-docs/api.json`; file ini salinan identik di repo FE (`docs/kontrak-api-fitur-baru.md`).

## 1. Izin modul

### Kunci izin

| Modul (`ModulIzin`) | Label | `lihat` | `kelola` (mencakup `lihat`) |
|---|---|---|---|
| `keuangan` | Keuangan | Semua tagihan dan pembayaran (termasuk bukti dan kwitansi), `GET /jenis-tagihan`, `GET /keringanan`, laporan, ekspor, tunggakan, `GET /pengaturan?grup=keuangan` | Tambah/ubah/hapus keringanan, tagihan sekali bayar, ubah tagihan, catat pembayaran tunai/transfer, terima/tolak pembayaran, notifikasi `pembayaran_masuk`, `pembayaran_menunggu` di beranda |
| `konten_website` | Konten Website | `GET /pengaturan?grup=profil\|landing\|beranda`, `GET /galeri-album`, `GET /galeri-album/{id}` | `PUT /pengaturan` untuk kunci grup `profil`, `landing`, `beranda`; `POST /pengaturan/upload`; tambah/ubah/hapus album dan foto galeri |
| `laporan_absensi` | Laporan Absensi | `GET /absensi/rekap`, `/rekap/export`, `/harian`; `GET /absensi?user_id=` peserta lain; foto absensi peserta lain | `PATCH /absensi/{id}/koreksi`, `POST /absensi/koreksi` untuk peserta lain (absensi sendiri ditolak 422 `BUSINESS_RULE`) |
| `data_murid` | Data Murid | Semua murid di `GET /murid` dan `GET /murid/{id}` (termasuk alumni), `GET /murid/tahun-lulus` | `POST /murid`, `PUT/DELETE /murid/{id}`, `GET /murid/{id}/kartu-akun`, `PATCH /murid/{id}/status`, `POST /murid/status-massal`, import murid |
| `pengumuman` | Pengumuman | Semua pengumuman di feed, termasuk draft dan sasaran `kelas`/`murid` | Target apa pun termasuk `is_publik`, ubah dan hapus pengumuman siapa pun |

`LevelIzin` = `lihat` \| `kelola`. Kepala Sekolah selalu `kelola` semua modul; wali murid tidak punya izin. Izin dibaca dari database di setiap request, jadi izin yang dicabut berlaku di request berikutnya tanpa masuk ulang.

Izin data murid tidak membuka modul lain: tagihan, rapor, kegiatan, dan pengumuman murid di luar kelas yang diampu tetap tertutup kecuali ada izin modulnya.

### Tetap khusus Kepala Sekolah

Manajemen guru (`/guru`, termasuk izin), pengaturan grup `absensi`, `ppdb`, dan `keuangan` (rekening, jatuh tempo, pengingat), wali murid (`/wali-murid`, ubah/lepas tautan wali di `/murid/{id}/wali/{wali_murid_id}`), tahun ajaran, kelas, penempatan, kenaikan kelas, jenis tagihan (tambah/ubah/hapus), generate tagihan manual, batalkan/aktifkan tagihan, keputusan rapor, elemen penilaian, PPDB, agenda (tambah/ubah/hapus), dan log aktivitas.

### Preset (`PresetIzin`)

| Kode | Nama | Izin |
|---|---|---|
| `bendahara` | Bendahara | keuangan `kelola` |
| `admin_website` | Admin Website | konten_website `kelola`, pengumuman `kelola` |
| `admin_absensi` | Admin Absensi | laporan_absensi `kelola` |
| `tata_usaha` | Tata Usaha | data_murid `kelola` |

Menerapkan preset mengganti seluruh izin guru; setelah itu izin bisa disesuaikan per modul.

### Bentuk `izin`

Objek berisi kelima modul, nilainya `"lihat"`, `"kelola"`, atau `null`:

```json
{ "keuangan": "kelola", "konten_website": null, "laporan_absensi": null, "data_murid": "lihat", "pengumuman": null }
```

## 2. Endpoint izin

### `GET /izin` (Kepala Sekolah)

```json
{
  "modul": [{ "kode": "keuangan", "label": "Keuangan", "deskripsi": "Lihat: …. Kelola: …." }],
  "level": [{ "kode": "lihat", "label": "Lihat" }, { "kode": "kelola", "label": "Kelola" }],
  "preset": [{ "kode": "bendahara", "nama": "Bendahara", "izin": { "keuangan": "kelola", "konten_website": null, "laporan_absensi": null, "data_murid": null, "pengumuman": null } }]
}
```

### `GET /guru`, `GET /guru/{id}` (Kepala Sekolah)

Data guru mendapat `izin` (bentuk di atas) menggantikan `bisa_kelola_keuangan`. Profil guru milik Kepala Sekolah selalu `kelola` semua modul.

### `PUT /guru/{id}/izin` (Kepala Sekolah)

Isian `{ "izin": { …kelima modul… } }`, semua kunci wajib dikirim (`null` = tidak punya izin), mengganti seluruh izin → data guru. Kunci kurang, modul tidak dikenal, atau level tidak dikenal → 422 `VALIDATION_ERROR` (`izin`, `izin.<modul>`). Profil guru milik Kepala Sekolah → 422 `BUSINESS_RULE`. Dicatat di log aktivitas `izin` event `diubah` dengan properti `{ sebelum, sesudah, preset: null }` (tidak dicatat kalau tidak ada perubahan).

### `POST /guru/{id}/izin/preset` (Kepala Sekolah)

Isian `{ "preset": "bendahara" }` → data guru. Log aktivitas `izin` event `preset_diterapkan`, properti `preset` berisi kode preset.

### `GET /auth/me` dan respons login

`user.izin` (bentuk di atas) menggantikan `user.permissions.kelola_keuangan`. `user.guru.bisa_kelola_keuangan` dihapus. Untuk wali murid, `wali_murid.anak[]` mendapat `status` (`StatusMurid`).

## 3. Status murid dan alumni

### Field murid

`MuridResource` (daftar dan detail) mendapat:

| Field | Tipe | Isi |
|---|---|---|
| `tanggal_keluar` | `string \| null` | Tanggal lulus, pindah, atau keluar (sudah ada); null untuk murid aktif dan alumni lama tanpa tanggal |
| `tahun_lulus` | `int \| null` | Hanya untuk status `lulus` |
| `sekolah_tujuan` | `string \| null` | Hanya untuk status `pindah` |
| `keterangan_status` | `string \| null` | Keterangan perubahan status terakhir; null untuk murid aktif |

`MuridDetailResource` mendapat `riwayat_status`, terbaru dulu:

```json
[{ "id": 4, "status_lama": "aktif", "status_baru": "pindah", "tanggal": "2026-09-30", "tahun_lulus": null, "sekolah_tujuan": "TK Pertiwi 3 Semarang", "keterangan": "Ikut orang tua pindah tugas.", "diubah_oleh": { "id": 1, "nama": "…" }, "created_at": "2026-10-05T09:00:00+07:00" }]
```

Riwayat dicatat untuk setiap perubahan status (satu murid, massal, luluskan kelas, dan kenaikan kelas dengan status `lulus`). Murid hasil `POST /murid` dan import tidak punya baris riwayat awal.

### `PUT /murid/{id}` (izin kelola data murid)

`status` dan `tanggal_keluar` tidak lagi diterima (422 `VALIDATION_ERROR`); status hanya diubah lewat endpoint di bawah.

### `PATCH /murid/{id}/status` (izin kelola data murid)

Isian:

| Field | Aturan |
|---|---|
| `status` | wajib, `aktif` \| `lulus` \| `pindah` \| `keluar` |
| `tanggal` | wajib, `YYYY-MM-DD`, tidak setelah hari ini |
| `tahun_lulus` | wajib untuk `lulus`, dilarang untuk status lain; 1950 sampai tahun ini |
| `sekolah_tujuan` | opsional, hanya untuk `pindah`, maks 150 |
| `keterangan` | opsional, maks 500 |

→ detail murid. Status lulus/pindah/keluar menutup penempatan di tahun ajaran aktif (`lulus` / `keluar`) sehingga murid tidak muncul di daftar kelas aktif dan tidak ikut generate tagihan berikutnya; tagihan lama tetap. Status `aktif` membuka lagi penempatannya. 422 `BUSINESS_RULE`: status sama, tanggal sebelum tanggal masuk, tahun lulus sebelum tahun masuk, kelas penuh saat diaktifkan kembali. Log aktivitas `murid` event `status_diubah`.

### `POST /murid/status-massal` (izin kelola data murid)

Isian yang sama dengan di atas ditambah salah satu dari `murid_ids: int[]` (1–500) atau `kelas_id: int` (semua murid berpenempatan aktif di kelas itu, untuk meluluskan satu kelas) → `{ "diubah": 28, "dilewati": 2 }`. Murid yang statusnya sudah sama dilewati. Semua perubahan dalam satu transaksi; satu pelanggaran membatalkan semuanya (422 `BUSINESS_RULE`). Kelas tanpa murid aktif → 422 `BUSINESS_RULE`. Log aktivitas `murid` event `status_diubah_massal`.

### Alumni: `GET /murid?filter[status]=lulus&filter[tahun_lulus]=2019&search=`

Filter baru `filter[tahun_lulus]` dan urutan `sort=tahun_lulus` / `-tahun_lulus`. Pemegang izin lihat data murid dan Kepala Sekolah melihat semua murid; guru lain tetap hanya murid kelasnya.

### `GET /murid/tahun-lulus` (izin lihat data murid)

→ `int[]`, tahun lulus yang punya alumni, terbaru dulu.

### Akun wali yang semua anaknya tidak aktif

Akun tetap aktif (tagihan lama, kwitansi, rapor, PPDB adik). Wali itu tidak lagi melihat dan tidak menerima notifikasi pengumuman target `semua` dan `wali_murid`; pengumuman yang menyasar anaknya tetap sampai.

## 4. Import murid

### `GET /murid/import/template` (izin kelola data murid)

File `template-import-murid.xlsx`: sheet `Murid` berisi judul kolom (NISN, NIK, dan tanggal berformat teks), sheet `Petunjuk` berisi aturan setiap kolom. Kolom berurutan:

`nama_lengkap`*, `nama_panggilan`*, `nisn`, `nik`, `jenis_kelamin`* (`L`/`P`), `tempat_lahir`*, `tanggal_lahir`*, `agama`*, `alamat`*, `anak_ke`, `catatan_khusus`, `tanggal_masuk`*, `status` (kosong = `aktif`), `tanggal_status`, `tahun_lulus` (wajib untuk `lulus`), `sekolah_tujuan` (hanya `pindah`), `keterangan` (hanya non-aktif), `kelas` (hanya `aktif`, nama kelas di tahun ajaran aktif).

### `POST /murid/import/pratinjau` (izin kelola data murid)

Multipart `file`: `.xlsx` atau `.csv` (koma atau titik koma), maks 2 MB, maks 500 baris data. Hanya sheet pertama dibaca; kolom di luar template diabaikan. Tidak menyimpan apa pun.

```json
{
  "ringkasan": { "total": 9, "valid": 7, "invalid": 2 },
  "baris": [
    { "nomor": 2, "data": { "nama_lengkap": "…", "status": "aktif", "tanggal_lahir": "2021-05-14", "…": "…" }, "valid": true, "alasan": [] },
    { "nomor": 5, "data": { "…": "…" }, "valid": false, "alasan": ["NISN 0211234599 sudah dipakai Nadia Salsabila."] }
  ]
}
```

`nomor` = nomor baris di spreadsheet (judul di baris 1). `data` berisi kedelapan belas kolom template sebagai `string | null` (`status` selalu terisi); tanggal sudah dirapikan menjadi `YYYY-MM-DD` (nomor seri tanggal Excel, `DD/MM/YYYY`, dan `DD-MM-YYYY` diterima).

Aturan per baris: data diri sama dengan `POST /murid`; NISN 10 digit dan opsional untuk semua status; `tanggal_status` opsional, dilarang untuk murid aktif, tidak setelah hari ini dan tidak sebelum tanggal masuk; tahun lulus tidak sebelum tahun masuk; murid lulus tanpa tanggal memakai akhir tahun ajaran yang selesai di tahun lulus itu, atau 30 Juni tahun itu kalau tahun ajarannya tidak tercatat; murid pindah/keluar tanpa tanggal disimpan tanpa tanggal. Duplikat: NISN yang sudah dipakai murid (termasuk yang dihapus) atau baris sebelumnya, dan untuk baris tanpa NISN, nama lengkap + tanggal lahir yang sama dengan murid terdaftar atau baris sebelumnya. Kelas yang tidak ada atau sudah penuh membuat baris invalid.

Error file (422 `VALIDATION_ERROR` di `errors.file`): format bukan xlsx/csv, lebih dari 2 MB, kolom wajib tidak ada, tidak ada baris data, lebih dari 500 baris, file tidak bisa dibaca.

### `POST /murid/import` (izin kelola data murid)

File yang sama. Diperiksa ulang, lalu semua baris valid disimpan dalam satu transaksi → `{ "dibuat": 7, "akun_wali_dibuat": 5, "gagal": [ …baris invalid, bentuk sama dengan pratinjau… ] }`. Murid aktif dibuatkan akun wali otomatis (username NIS, password awal tanggal lahir DDMMYYYY, wajib diganti) dan ditempatkan di `kelas` kalau diisi; murid lulus/pindah/keluar tanpa akun wali dan tidak ikut generate tagihan. NIS dibuat dari tahun `tanggal_masuk`. Kalau penyimpanan gagal di tengah (misalnya NISN dipakai data lain saat import berjalan), tidak ada yang tersimpan (422 `BUSINESS_RULE`). Hash password awal dibuat per kelompok 50 baris di luar transaksi dengan batas waktu PHP dihitung ulang setiap kelompok. Log aktivitas `murid` event `diimpor` dengan `{ nama_file, dibuat, akun_wali_dibuat, gagal }`.

Laporan baris gagal dibuat FE dari `gagal` (CSV berkolom template ditambah `alasan`), dan bisa diperbaiki lalu diimpor ulang.

## 5. Format error

| Keadaan | Status | `code` | `message` |
|---|---|---|---|
| Guru tanpa izin modul di route modul | 403 | `FORBIDDEN` | `Anda belum punya izin <lihat\|kelola> <Label Modul>. Minta Kepala Sekolah menambahkannya di halaman Hak Akses Guru.` |
| Guru di bagian khusus Kepala Sekolah (route `role:super_admin`) | 403 | `FORBIDDEN` | `Anda tidak punya akses untuk tindakan ini.` |
| Guru membaca/mengubah grup pengaturan khusus Kepala Sekolah | 403 | `FORBIDDEN` | `Bagian ini khusus Kepala Sekolah.` |
| Wali murid di route modul | 403 | `FORBIDDEN` | pesan izin seperti baris pertama |
| Pelanggaran aturan status/import/izin | 422 | `BUSINESS_RULE` | pesan sebabnya |
| Isian tidak valid | 422 | `VALIDATION_ERROR` | `Data tidak valid`, `errors` per field |

## 6. Perubahan lain

- Notifikasi `absensi_dikoreksi`: judul dan pesan menyebut pengoreksinya (`Absensi dikoreksi Kepala Sekolah` atau `Absensi dikoreksi <nama guru>`).
- Log aktivitas baru: `izin` (diubah, preset_diterapkan), `murid` (status_diubah, status_diubah_massal, diimpor), `keringanan` (dibuat, diubah, dihapus), `jenis_tagihan` (dibuat, diubah, dihapus), `tagihan` event `dibuat_sekali`. `filter[jenis]` di `GET /log-aktivitas` menerima `izin`, `murid`, `keringanan`, `jenis_tagihan`, dan `absensi`.
- Kenaikan kelas dengan status `lulus` mengisi `tahun_lulus` (tahun akhir tahun ajaran aktif) dan riwayat status.
