# Kontrak API: hak akses guru, status murid, NIS murid, dan formulir data murid

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

Manajemen guru (`/guru`, termasuk izin), pengisian dan perubahan NIS murid (`/murid/{id}/nis`, `/murid/nis/...`), pengaturan grup `absensi`, `ppdb`, dan `keuangan` (rekening, jatuh tempo, pengingat), wali murid (`/wali-murid`, ubah/lepas tautan wali di `/murid/{id}/wali/{wali_murid_id}`), tahun ajaran, kelas, penempatan, kenaikan kelas, jenis tagihan (tambah/ubah/hapus), generate tagihan manual, batalkan/aktifkan tagihan, keputusan rapor, elemen penilaian, PPDB, agenda (tambah/ubah/hapus), dan log aktivitas.

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

File `template-import-murid.xlsx`. Sheet `Murid` berisi judul kolom; kolom wajib diberi `*` (`nama_lengkap*`), dan pembaca import membuang `*` sehingga file lama tanpa tanda itu tetap terbaca. Kolom NISN, NIK, tanggal, dan nomor HP berformat teks. Kolom pilihan punya dropdown (data validation, peringatan saja) yang merujuk daftar di sheet `Petunjuk`. Sheet `Petunjuk`: kolom A-C berisi nama kolom, wajib atau tidak, dan arti; mulai kolom E berisi daftar pilihan (Jenis kelamin, Agama, Kewarganegaraan, Status yatim/piatu, Golongan darah, Imunisasi, Kontak utama, Status murid, Status ayah/ibu, Hubungan wali, Pendidikan terakhir, Pekerjaan, Penghasilan per bulan) dengan label yang dibaca manusia. Kolom berurutan:

`nama_lengkap`*, `nama_panggilan`*, `nisn`, `nik`, `jenis_kelamin`*, `tempat_lahir`*, `tanggal_lahir`*, `agama`*, `alamat`* (jalan, RT/RW), `desa_kelurahan`, `kecamatan`, `kabupaten_kota`, `provinsi`, `kewarganegaraan`, `anak_ke`, `jumlah_saudara_kandung`, `jumlah_saudara_tiri`, `jumlah_saudara_angkat`, `status_yatim_piatu`, `asal_sekolah`, `bahasa_sehari_hari`, `jarak_rumah_km`, `golongan_darah`, `riwayat_penyakit`, `tinggi_badan`, `berat_badan`, `imunisasi` (dipisah koma), `imunisasi_lainnya`, `ciri_khusus`, `catatan_khusus`, `kontak_utama`, `tanggal_masuk`*, `status` (kosong = `aktif`), `tanggal_status`, `tahun_lulus` (wajib untuk `lulus`), `sekolah_tujuan` (hanya `pindah`), `keterangan` (hanya non-aktif), `kelas` (hanya `aktif`, nama kelas di tahun ajaran aktif), lalu untuk `ayah` dan `ibu`: `<peran>_status`, `<peran>_nama`, `<peran>_tempat_lahir`, `<peran>_tanggal_lahir`, `<peran>_agama`, `<peran>_kewarganegaraan`, `<peran>_pendidikan_terakhir`, `<peran>_pekerjaan`, `<peran>_pekerjaan_lainnya`, `<peran>_penghasilan`, `<peran>_alamat_rumah` (`sama` = sama dengan alamat anak), `<peran>_alamat_kantor`, `<peran>_no_hp`, dan untuk wali kolom yang sama dengan `wali_hubungan` dan `wali_hubungan_lainnya` sebagai ganti `wali_status`.

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

`nomor` = nomor baris di spreadsheet (judul di baris 1). `data` berisi semua kolom template sebagai `string | null` (`status` selalu terisi); tanggal sudah dirapikan menjadi `YYYY-MM-DD` (nomor seri tanggal Excel, `DD/MM/YYYY`, dan `DD-MM-YYYY` diterima), dan kolom pilihan sudah berupa kode (label atau kode diterima, tanpa beda huruf besar).

Aturan per baris: data diri dan format kolom formulir sama dengan `POST /murid` (bagian 8); hanya kolom bertanda `*` yang wajib, jadi murid yang formulirnya belum lengkap tetap bisa diimpor dan tertandai `data_lengkap = false`. Pilihan di luar daftar → `<Nama kolom> tidak ada di daftar pilihan. Lihat sheet Petunjuk.`; kontak utama harus orang yang diisi, masih hidup, dan punya nomor HP. NIK yang sudah dipakai murid → `NIK <nik> sudah dipakai <nama>.`, NIK sama di dua baris → `NIK <nik> sama dengan baris <n>.`. NISN 10 digit dan opsional untuk semua status; `tanggal_status` opsional, dilarang untuk murid aktif, tidak setelah hari ini dan tidak sebelum tanggal masuk; tahun lulus tidak sebelum tahun masuk; murid lulus tanpa tanggal memakai akhir tahun ajaran yang selesai di tahun lulus itu, atau 30 Juni tahun itu kalau tahun ajarannya tidak tercatat; murid pindah/keluar tanpa tanggal disimpan tanpa tanggal. Duplikat: NISN yang sudah dipakai murid (termasuk yang dihapus) atau baris sebelumnya, dan untuk baris tanpa NISN, nama lengkap + tanggal lahir yang sama dengan murid terdaftar atau baris sebelumnya. Kelas yang tidak ada atau sudah penuh membuat baris invalid.

Error file (422 `VALIDATION_ERROR` di `errors.file`): format bukan xlsx/csv, lebih dari 2 MB, kolom wajib tidak ada, tidak ada baris data, lebih dari 500 baris, file tidak bisa dibaca.

### `POST /murid/import` (izin kelola data murid)

File yang sama. Diperiksa ulang, lalu semua baris valid disimpan dalam satu transaksi → `{ "dibuat": 7, "gagal": [ …baris invalid, bentuk sama dengan pratinjau… ] }`. Murid disimpan tanpa NIS dan tanpa akun wali; murid aktif dibuatkan akun wali saat Kepala Sekolah mengisi NIS (bagian 5). Murid aktif ditempatkan di `kelas` kalau diisi; murid lulus/pindah/keluar tidak ikut generate tagihan. Kalau penyimpanan gagal di tengah (misalnya NISN atau NIK dipakai data lain saat import berjalan), tidak ada yang tersimpan (422 `BUSINESS_RULE`). Ayah, ibu, dan wali disimpan seperti `POST /murid`. Log aktivitas `murid` event `diimpor` dengan `{ nama_file, dibuat, gagal }`.

Laporan baris gagal dibuat FE dari `gagal` (CSV berkolom template ditambah `alasan`), dan bisa diperbaiki lalu diimpor ulang.

## 5. NIS murid (Kepala Sekolah)

NIS tidak dibuat otomatis. Murid baru (`POST /murid`, import, PPDB yang diterima) punya `nis` null dan belum punya akun wali sampai Kepala Sekolah mengisi NIS. Daftar murid yang menunggu NIS: `GET /murid?filter[menunggu_nis]=true` (filter juga menerima `false`, `1`, `0`); beranda Kepala Sekolah memuat `tertunda.murid_menunggu_nis` (murid aktif tanpa NIS).

Aturan NIS (sama di ketiga cara): dinormalkan seperti username login wali (huruf besar, semua spasi dibuang), hanya huruf, angka, titik, strip, dan garis miring, maksimal 20 karakter, tidak dipakai murid lain (termasuk yang dihapus) dan tidak dipakai sebagai username akun mana pun. Mengisi NIS murid aktif yang belum tertaut ke wali membuat akun wali otomatis (username NIS, password awal tanggal lahir DDMMYYYY, wajib diganti); murid dari PPDB tanpa login memakai hubungan dan nomor HP formulirnya, akun itu dicatat sebagai `pendaftaran.wali_murid_id`, dan menerima notifikasi `pendaftaran_diproses` (diterima). Murid yang sudah tertaut ke wali (PPDB dari wali yang login) atau tidak aktif hanya mendapat NIS.

### `PATCH /murid/{id}/nis`

`{ "nis": "001/TA8" }` → detail murid. Juga untuk mengubah NIS: username akun yang memakai NIS lama ikut diganti (sesi tetap berlaku) dan akun aktif itu mendapat notifikasi `username_diubah` (`url` `/dashboard/profil`); akun keluarga yang username-nya NIS kakak/adik tidak berubah. Pelanggaran → 422 `VALIDATION_ERROR` di `errors.nis` dengan salah satu pesan:

- `NIS wajib diisi.`
- `NIS maksimal 20 karakter.`
- `NIS hanya boleh berisi huruf, angka, titik, strip, dan garis miring.`
- `NIS <nis> sudah dipakai <nama murid>.`
- `NIS <nis> sudah dipakai sebagai username akun wali murid lain.`
- `NIS ini sama dengan NIS sekarang.`

Log aktivitas `murid` event `nis_diisi` (`{ nis, akun_wali_dibuat }`) atau `nis_diubah` (`{ dari, ke }`), dan `akun` event `username_diubah`.

### `GET /murid/nis/template`

File `murid-menunggu-nis.xlsx`, sheet `Murid`: murid aktif yang menunggu NIS, urut nama, kolom `id`, `nama_lengkap`, `tanggal_lahir` (teks, misalnya `14 Mei 2021`), dan `nis` (kosong, berformat teks supaya nol di depan tidak hilang).

### `POST /murid/nis/import/pratinjau` dan `POST /murid/nis/batch/pratinjau`

Import: multipart `file` (.xlsx saja, maks 2 MB, maks 500 baris; hanya `id`, `nama_lengkap`, dan `nis` yang dibaca). Tabel web: `{ "items": [{ "murid_id": 12, "nis": "2026001" }] }` (1–500 item, `murid_id` tidak dobel). Tidak menyimpan apa pun.

```json
{
  "ringkasan": { "total": 3, "valid": 1, "invalid": 2, "akun_wali": 1 },
  "baris": [
    { "nomor": 2, "murid_id": 12, "nama_lengkap": "Raka Aditya Pratama", "nis": "0012", "valid": true, "akun_wali": true, "alasan": [] },
    { "nomor": 3, "murid_id": 13, "nama_lengkap": "Salma Azzahra", "nis": "0012", "valid": false, "akun_wali": false, "alasan": ["NIS 0012 dobel dengan Raka Aditya Pratama (baris 2)."] }
  ]
}
```

`nomor` = nomor baris spreadsheet (import) atau urutan item mulai 1 (tabel). `nis` sudah dinormalkan; `nama_lengkap` null untuk tabel. Alasan baris selain aturan NIS di atas: `NIS <nis> dobel dengan <nama murid lain>.` (tabel) atau `... dengan <nama> (baris <n>).` (import), semua baris yang dobel ditandai, `Murid dengan ID <id> tidak ditemukan.`, `<nama> sudah punya NIS <nis>.`, `ID murid kosong atau bukan angka. ...`, dan (import) `Nama <nama di file> tidak sama dengan murid ID <id> (<nama>). ...` (dibandingkan tanpa beda huruf besar dan spasi berlebih). `akun_wali` true untuk baris valid yang akan membuat akun wali; `ringkasan.akun_wali` menjumlahkannya.

Error file (422 `VALIDATION_ERROR` di `errors.file`): bukan .xlsx, lebih dari 2 MB, kolom `id`/`nama_lengkap`/`nis` tidak ada, tidak ada baris data, lebih dari 500 baris, file tidak bisa dibaca.

### `POST /murid/nis/import` dan `POST /murid/nis/batch`

Isian yang sama. Diperiksa ulang, lalu NIS semua baris valid diisi dalam satu transaksi → `{ "diisi": 1, "akun_wali_dibuat": 1, "gagal": [ …baris invalid, bentuk sama dengan pratinjau… ] }`. Kalau NIS bentrok dengan data yang baru tersimpan saat proses berjalan, tidak ada yang disimpan (422 `BUSINESS_RULE`). Hash password awal dibuat per kelompok 50 akun di luar transaksi dengan batas waktu PHP dihitung ulang setiap kelompok.

### Murid tanpa NIS di endpoint lain

- `nis` bertipe `string | null` di semua bentuk murid (daftar, detail, anak wali, kelas, tagihan, keringanan, rapor, PPDB, absensi murid, tunggakan).
- `GET /murid/{id}/kartu-akun` → 422 `BUSINESS_RULE` `NIS <panggilan> belum diisi Kepala Sekolah, jadi akun walinya belum dibuat.` Nama file kartu akun mengganti karakter selain huruf, angka, titik, strip, dan garis bawah dengan `-` (`001/TA8` → `kartu-akun-001-TA8.pdf`).
- PDF kwitansi tanpa keterangan NIS, PDF rapor dan rekap absensi murid menulis `-`; ekspor Excel membiarkan selnya kosong.
- `DELETE /murid/{id}` mengosongkan `nis`, `nisn`, dan `nik` murid yang dihapus serta username akun otomatisnya yang belum dipakai, supaya nomor itu bisa dipakai lagi.
- Akun wali otomatis memakai nama, hubungan, dan nomor HP kontak utama formulir (bagian 8). Murid tanpa kontak utama (data lama) tetap mendapat nama `Wali <nama panggilan>`, hubungan `wali`, tanpa nomor HP.

## 6. Format error

| Keadaan | Status | `code` | `message` |
|---|---|---|---|
| Guru tanpa izin modul di route modul | 403 | `FORBIDDEN` | `Anda belum punya izin <lihat\|kelola> <Label Modul>. Minta Kepala Sekolah menambahkannya di halaman Hak Akses Guru.` |
| Guru di bagian khusus Kepala Sekolah (route `role:super_admin`) | 403 | `FORBIDDEN` | `Anda tidak punya akses untuk tindakan ini.` |
| Guru membaca/mengubah grup pengaturan khusus Kepala Sekolah | 403 | `FORBIDDEN` | `Bagian ini khusus Kepala Sekolah.` |
| Wali murid di route modul | 403 | `FORBIDDEN` | pesan izin seperti baris pertama |
| Pelanggaran aturan status/import/izin/NIS | 422 | `BUSINESS_RULE` | pesan sebabnya |
| Isian tidak valid | 422 | `VALIDATION_ERROR` | `Data tidak valid`, `errors` per field |

## 7. Perubahan lain

- Notifikasi `absensi_dikoreksi`: judul dan pesan menyebut pengoreksinya (`Absensi dikoreksi Kepala Sekolah` atau `Absensi dikoreksi <nama guru>`).
- Log aktivitas baru: `izin` (diubah, preset_diterapkan), `murid` (status_diubah, status_diubah_massal, diimpor, nis_diisi, nis_diubah), `akun` (username_diubah), `keringanan` (dibuat, diubah, dihapus), `jenis_tagihan` (dibuat, diubah, dihapus), `tagihan` event `dibuat_sekali`. `filter[jenis]` di `GET /log-aktivitas` menerima `izin`, `murid`, `keringanan`, `jenis_tagihan`, dan `absensi`.
- Kenaikan kelas dengan status `lulus` mengisi `tahun_lulus` (tahun akhir tahun ajaran aktif) dan riwayat status.

## 8. Formulir data murid

Isian formulir pendaftaran resmi sekolah, dipakai di PPDB online, tambah dan ubah murid, import, detail murid, detail pendaftaran, dan dashboard wali. Berkas Kartu Keluarga dan akta kelahiran tidak diunggah; sekolah hanya mencentang berkas yang sudah diterima.

### Data anak

Selain data diri lama (`nama_lengkap`, `nama_panggilan`, `nik`, `jenis_kelamin`, `tempat_lahir`, `tanggal_lahir`, `agama`, `alamat`, `anak_ke`), murid dan pendaftaran punya field berikut. Semua boleh null; data lama tidak diubah.

| Field | Tipe | Isi |
|---|---|---|
| `alamat` | string | Sekarang berarti jalan, nomor rumah, RT/RW. Alamat lengkap lama tetap di sini |
| `desa_kelurahan`, `kecamatan`, `kabupaten_kota`, `provinsi` | string, maks 100 | |
| `asal_sekolah` | string, maks 150 | |
| `kewarganegaraan` | `wni` \| `wna` | |
| `jumlah_saudara_kandung`, `jumlah_saudara_tiri`, `jumlah_saudara_angkat` | int 0-20 | FE mengisi 0 sebagai bawaan |
| `status_yatim_piatu` | `tidak` \| `yatim` \| `piatu` \| `yatim_piatu` | Ayah (yatim) atau ibu (piatu) yang sudah meninggal cukup diisi nama |
| `bahasa_sehari_hari` | string, maks 50 | |
| `jarak_rumah_km` | number, maks 2 desimal, 0-999.99 | |
| `golongan_darah` | `A` \| `B` \| `AB` \| `O` \| `tidak_tahu` | |
| `riwayat_penyakit` | string, maks 1000 | Penyakit yang pernah diderita |
| `tinggi_badan` | number cm, 1 desimal, 30-200 | Data saat masuk, terpisah dari tinggi badan rapor |
| `berat_badan` | number kg, 1 desimal, 2-150 | Data saat masuk |
| `imunisasi` | `("bcg" \| "hepatitis_b" \| "polio" \| "dpt" \| "campak_mr")[]` | Multipart: `imunisasi[]`; kirim `imunisasi=` (string kosong) untuk mengosongkan |
| `imunisasi_lainnya` | string, maks 255 | |
| `ciri_khusus` | string, maks 1000 | Terpisah dari `catatan_khusus` (catatan untuk guru) |
| `kontak_utama` | `ayah` \| `ibu` \| `wali` | Orang yang nama, hubungan, dan nomor HP-nya dipakai akun wali otomatis saat NIS diisi |

Agama anak dan orang tua di PPDB dan orang tua: `Islam`, `Kristen`, `Katolik`, `Hindu`, `Buddha`, `Konghucu` (nilai = label). Agama anak di `POST/PUT /murid` tetap teks bebas maks 20 karena data lama.

### Ayah, ibu, dan wali

Request dan respons memakai objek `ayah`, `ibu`, `wali` dengan field yang sama:

| Field | Tipe |
|---|---|
| `status` (ayah, ibu) | `kandung` \| `tiri` \| `angkat` |
| `hubungan` (wali) | `kakek` \| `nenek` \| `paman` \| `bibi` \| `kakak` \| `lainnya` |
| `hubungan_lainnya` (wali) | string, maks 50, kalau `hubungan` = `lainnya` |
| `nama` | string |
| `tempat_lahir` | string, maks 100 |
| `tanggal_lahir` | `YYYY-MM-DD`, sebelum hari ini |
| `agama` | pilihan agama |
| `kewarganegaraan` | `wni` \| `wna` |
| `pendidikan_terakhir` | `tidak_sekolah` \| `sd` \| `smp` \| `slta` \| `d1_d3` \| `d4_s1` \| `s2` \| `s3` (label: Tidak sekolah, SD, SMP, SLTA, D1-D3, D4/S1, S2, S3) |
| `pekerjaan` | `tidak_bekerja` \| `mengurus_rumah_tangga` \| `pns_tni_polri` \| `karyawan_swasta` \| `karyawan_bumn` \| `wiraswasta` \| `pedagang` \| `petani_peternak` \| `nelayan` \| `buruh` \| `pensiunan` \| `lainnya` |
| `pekerjaan_lainnya` | string, maks 100, kalau `pekerjaan` = `lainnya` |
| `penghasilan` | `di_bawah_500rb` \| `500rb_1jt` \| `1jt_2jt` \| `2jt_5jt` \| `5jt_20jt` \| `di_atas_20jt` \| `tidak_berpenghasilan` (label di enum `Penghasilan`, misalnya "Rp1.000.000 - Rp1.999.999") |
| `alamat_sama_dengan_anak` | bool; kalau true, `alamat_rumah` dikosongkan |
| `alamat_rumah`, `alamat_kantor` | string, maks 500 |
| `no_hp` | `08…`, 10-15 digit |

Peran yang tidak dikirim tidak berubah. Peran yang dikirim diganti seluruhnya; objek yang semua isiannya kosong menghapus data orang itu. Multipart memakai kunci bertingkat (`ayah[nama]`, `ayah[alamat_sama_dengan_anak]=1`).

### Field wajib dan "Data belum lengkap"

Wajib: `nama_lengkap`, `nama_panggilan`, `nik`, `jenis_kelamin`, `tempat_lahir`, `tanggal_lahir`, `alamat`, `desa_kelurahan`, `kecamatan`, `kabupaten_kota`, `provinsi`, `agama`, `kewarganegaraan`, `anak_ke`, `status_yatim_piatu`, `kontak_utama`; untuk ayah dan ibu: `status`, `nama`, `tempat_lahir`, `tanggal_lahir`, `agama`, `kewarganegaraan`, `pendidikan_terakhir`, `pekerjaan` (+ `pekerjaan_lainnya` kalau `lainnya`), `alamat_rumah` atau `alamat_sama_dengan_anak`; ayah atau ibu yang sudah meninggal cukup `nama`. Wali opsional; kalau diisi, `hubungan` (+ `hubungan_lainnya`), `nama`, dan `no_hp` wajib. Minimal satu nomor HP di antara ayah, ibu (yang masih hidup), atau wali. Kontak utama harus orang yang datanya ada, masih hidup, dan punya nomor HP; aturan kontak utama ini juga berlaku untuk staf, import, dan wali.

Pesan wajib: `<Label> wajib diisi.` (`NIK anak wajib diisi.`, `Pekerjaan ayah wajib diisi.`), `Tulis pekerjaan ibu.`, `Isi minimal satu nomor HP ayah, ibu, atau wali.` (di field `ayah.no_hp`, atau `ibu.no_hp` kalau ayah sudah meninggal), `Data wali belum diisi, jadi wali tidak bisa dipilih sebagai kontak utama.`, `Ibu sudah meninggal menurut status yatim/piatu. Pilih kontak utama lain.`, `Nomor HP ibu wajib diisi karena ibu dipilih sebagai kontak utama.` (di `ibu.no_hp`).

Murid menyimpan `data_lengkap` (dihitung ulang setiap formulir atau orang tua disimpan). Murid lama `false`.

### `POST /murid`, `PUT /murid/{id}` (izin kelola data murid)

Semua field di atas boleh dikirim; yang wajib tetap data diri lama (`nama_lengkap`, `nama_panggilan`, `jenis_kelamin`, `tempat_lahir`, `tanggal_lahir`, `agama`, `alamat`, `tanggal_masuk`), sehingga staf bisa menyimpan formulir yang belum lengkap. `nik` 16 digit dan unik di antara murid (`NIK anak sudah digunakan.`). Kontak utama diperiksa seperti di atas.

### `POST /pendaftaran` (W) dan `POST /public/pendaftaran`

Formulir wajib lengkap (aturan di atas, pesan per field) dan `pernyataan: true` wajib (`Centang pernyataan bahwa data yang diisi benar sebelum mengirim pendaftaran.`). Waktu menyetujui pernyataan = `created_at`. `no_hp`, `nama_ayah`, `pekerjaan_ayah`, `nama_ibu`, `pekerjaan_ibu` dihapus. `hubungan` hanya untuk `POST /pendaftaran` (hubungan akun wali yang login dengan anak, dipakai saat menautkan); `POST /public/pendaftaran` mengabaikannya dan `hubungan` pendaftaran itu null. Nomor pendaftaran tetap `kode` (`PPDB-YYYY-XXXX`, urut per tahun ajaran tujuan).

### Bentuk respons

- `GET /murid` (daftar) dan bentuk murid lain: tanpa `nik`; tambah `data_lengkap` (bool). Filter `filter[data_lengkap]=true|false|1|0`.
- `GET /murid/{id}` (detail, juga respons `POST/PUT /murid`, `PATCH /murid/{id}/berkas`, `PATCH /wali/anak/{id}`): semua field data anak di atas, `orang_tua: { ayah, ibu, wali }` (masing-masing objek atau null), `data_kurang: string[]` (field wajib yang kosong, nama field request seperti `nik` atau `ayah.pekerjaan`), dan `berkas`. `nik` hanya dikirim ke Kepala Sekolah, pemegang izin `data_murid` (lihat), dan wali anak itu; guru pengampu tanpa izin tidak menerima field `nik` sama sekali. `wali` tetap daftar akun wali murid yang tertaut.
- `GET /pendaftaran` (daftar): tanpa `nik`, tanpa kolom orang tua lama. `hubungan` null untuk pendaftaran tanpa login. `catatan` = alasan penolakan atau alasan mengundurkan diri.
- `GET /pendaftaran/{id}` (detail): `nik`, `anak_ke`, semua field data anak, `orang_tua`, `berkas`, `status_sebelum_mundur` (`diajukan` \| `diverifikasi` \| `diterima` \| null).
- `berkas`: `{ kartu_keluarga: Centang | null, akta_kelahiran: Centang | null }`, `Centang` = `{ diterima_at, diterima_oleh: { id, nama } | null }`.
- `GET /wali/anak`, anak di `GET /dashboard` (W), dan respons `POST /wali/tambah-anak`: tambah `data_lengkap`.
- `GET /dashboard` (SA): `tertunda.murid_data_belum_lengkap` = murid aktif dengan `data_lengkap = false`.

### Berkas fisik

- `PATCH /pendaftaran/{id}/berkas` (SA) dan `PATCH /murid/{id}/berkas` (izin kelola data murid): `{ kartu_keluarga?: bool, akta_kelahiran?: bool }`, minimal satu → detail pendaftaran/murid. `true` mencentang (waktu dan pencentang dicatat; berkas yang sudah dicentang tidak berubah), `false` menghapus centang. Pendaftaran hanya bisa diubah saat `diajukan` atau `diverifikasi` (selain itu 422 `BUSINESS_RULE`); centangnya disalin ke murid saat diterima. Log aktivitas `ppdb`/`murid` event `berkas_diubah` berisi isian request.
- `POST /pendaftaran/{id}/terima` tidak memeriksa berkas; FE memberi peringatan kalau belum lengkap. `diverifikasi` sekarang berarti data formulir sudah dicek sekolah; notifikasinya "Data pendaftaran <panggilan> (<kode>) sudah dicek sekolah. Tunggu keputusan akhir dari sekolah."

### Mengundurkan diri (SA)

- `POST /pendaftaran/{id}/mengundurkan-diri` `{ alasan?: string maks 500 }` → detail pendaftaran, status `mengundurkan_diri`, `status_sebelum_mundur` diisi, `catatan` = alasan. Dari `diajukan`, `diverifikasi`, atau `diterima`. Untuk `diterima`: ditolak (422 `BUSINESS_RULE` `<nama> sudah punya tagihan, rapor, atau absensi, jadi dianggap sudah mulai sekolah. Ubah status muridnya menjadi Keluar.`) kalau murid sudah punya tagihan (status apa pun), rapor, atau absensi murid; selain itu murid dihapus (soft delete) seperti `DELETE /murid/{id}`: penempatan kelas dihapus, `nis`, `nisn`, `nik` dikosongkan, akun wali otomatis yang belum dipakai dinonaktifkan; `murid` di respons menjadi null. Tanpa notifikasi ke wali.
- Pendaftar yang mengundurkan diri tidak dihitung kuota dan NIK-nya boleh mendaftar lagi (sama seperti ditolak). `GET /pendaftaran` untuk Kepala Sekolah tanpa `filter[status]` tidak memuatnya; lihat dengan `filter[status]=mengundurkan_diri`. Wali murid tetap melihat semua pendaftarannya.
- `POST /pendaftaran/{id}/batalkan-mundur` → kembali ke `status_sebelum_mundur` (hanya `diajukan` atau `diverifikasi`), `catatan` dikosongkan. 422 `BUSINESS_RULE` kalau mundur setelah diterima, kuota sudah penuh, atau NIK anak sudah dipakai pendaftaran lain yang aktif atau murid.
- Log aktivitas `ppdb` event `mengundurkan_diri` (`dari`, `alasan`) dan `mundur_dibatalkan` (`ke`).

### `PATCH /wali/anak/{id}` (W)

Wali melengkapi field formulir anaknya yang masih kosong: `nik` (16 digit, unik), `anak_ke`, field data anak di atas, dan objek `ayah`, `ibu`, `wali` (field per orang). Field yang sudah terisi dan dikirim dengan nilai yang sama diabaikan; dengan nilai lain → 422 `VALIDATION_ERROR` di field itu: `<Nama field> sudah terisi. Perubahan data yang sudah ada dilakukan lewat sekolah.`. Alamat rumah dan "sama dengan alamat anak" dihitung satu isian. Kontak utama diperiksa setelah digabung. Anak yang tidak tertaut ke akun itu → 404. → detail murid (dengan `nik`, `data_kurang`). Log aktivitas `murid` event `dilengkapi_wali` dengan `{ field: string[] }` (nama field saja, tanpa nilai).

### Data lama

Migration `pindahkan_orang_tua_pendaftaran` memindahkan `nama_ayah`, `pekerjaan_ayah`, `nama_ibu`, `pekerjaan_ibu`, dan `no_hp` pendaftaran lama ke tabel `orang_tua` (pekerjaan teks bebas masuk `pekerjaan_lainnya` dengan `pekerjaan = lainnya`; `no_hp` masuk ke orang sesuai `hubungan`, yang sekaligus menjadi `kontak_utama`), lalu menghapus kolom lama. Murid hasil PPDB tanpa login yang masih menunggu NIS ikut mendapat salinan orang tua dan `kontak_utama`, supaya akun walinya tetap memakai nomor HP formulir. NIK murid yang sudah dihapus dikosongkan; migration berhenti dengan daftar nama kalau ada NIK dobel di murid yang belum dihapus.

### Log aktivitas

NIK anak tidak pernah ditulis ke log. Event baru: `ppdb` (`berkas_diubah`, `mengundurkan_diri`, `mundur_dibatalkan`), `murid` (`berkas_diubah`, `dilengkapi_wali`).

