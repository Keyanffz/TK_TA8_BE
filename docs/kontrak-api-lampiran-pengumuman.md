# Kontrak API: lampiran pengumuman

Kontrak untuk lampiran di pengumuman, disetujui pemilik repo. Kode backend ada di branch `be/lampiran-pengumuman`, tetapi tesnya belum dijalankan dan `api.json` belum diekspor karena container sesi penulisannya tidak punya PHP 8.4. FE baru mulai setelah `api.json` backend memuat isi dokumen ini, karena FE tidak memakai field di luar kontrak. Semua endpoint memakai prefix `/api/v1`, header `Authorization: Bearer` (kecuali endpoint publik), dan format respons A7.

Ringkasannya ada di Bagian A (A2.9, A3, A4 tabel `pengumuman`, A7), yang identik di `PROMPT_BE_TK.md` dan `PROMPT_FE_TK.md`, serta di B5 dan B6.10 `PROMPT_BE_TK.md`. Salinan dokumen ini juga ada di `docs/` repo FE.

Nama konstanta dan fungsi di bawah merujuk ke kode backend yang sudah ada (`app/Services/MediaService.php`).

## 1. Aturan

- Satu lampiran per pengumuman, opsional.
- Jenis file: PDF dan gambar, yaitu ekstensi `MediaService::EKSTENSI_DOKUMEN` (`jpg`, `jpeg`, `png`, `webp`, `pdf`). Ukuran maksimal 5 MB (`MediaService::UKURAN_MAKSIMAL_KB`).
- File disimpan di disk privat (`MediaService::DISK_PRIVAT`, folder `pengumuman`) lewat `simpanDokumen()`, sama dengan dokumen PPDB: PDF apa adanya, gambar diperkecil ke lebar 1600 px dan disimpan sebagai JPEG (B5). Ekstensi nama tampilan mengikuti file yang tersimpan, jadi lampiran `Jadwal.png` menjadi `Jadwal.jpg`.
- Yang boleh menambah, mengganti, atau menghapus lampiran sama dengan yang boleh membuat atau mengubah pengumuman itu. Aturan `POST /pengumuman` dan `PUT /pengumuman/{id}` tidak berubah.
- Yang boleh membuka lampiran sama dengan yang boleh melihat pengumuman itu (`GET /pengumuman/{id}`), ditambah pengunjung website untuk pengumuman publik yang sudah terbit.
- Mengganti lampiran menghapus file lama. Menghapus pengumuman menghapus file lampirannya.
- Nama file asli disimpan untuk ditampilkan dan dipakai sebagai nama unduhan. Bagian path, garis miring, dan karakter kontrol dibuang (nama ini masuk header `Content-Disposition`), lalu dibatasi 200 karakter ditambah ekstensi.

## 2. Kolom tabel `pengumuman`

| Kolom | Isi |
|---|---|
| `lampiran_path` | sudah ada (string nullable), path di disk privat |
| `lampiran_nama` | baru, string nullable: nama file asli |
| `lampiran_ukuran` | baru, unsigned int nullable: ukuran dalam byte |

Ketiga kolom terisi bersama atau kosong bersama.

## 3. Endpoint

### `POST /pengumuman` dan `PUT /pengumuman/{id}`

Body boleh JSON seperti sekarang, atau `multipart/form-data` (seperti `PUT /murid/{id}` dan `PUT /auth/profil`). Lampiran hanya bisa dikirim lewat multipart. Di multipart, array dikirim sebagai `kelas_ids[]` dan `murid_ids[]`, dan boolean sebagai `1`/`0`. Field lama tidak berubah: `judul`, `isi`, `target`, `kelas_ids`, `murid_ids`, `is_publik`, `is_pinned`, `publish`.

Field baru:
- `lampiran` (file, opsional): `mimes:jpg,jpeg,png,webp,pdf`, `max:5120`. Di `PUT`, file baru menggantikan lampiran lama.
- `hapus_lampiran` (boolean, opsional, hanya `PUT`): `1` menghapus lampiran. Kalau dikirim bersama `lampiran`, dibalas 422 `VALIDATION_ERROR`.

Pesan validasi:
- `lampiran.mimes`: "Lampiran harus berupa PDF atau gambar (JPG, PNG, WebP)."
- `lampiran.max`: "Ukuran lampiran maksimal 5 MB."

Respons tetap data pengumuman, ditambah `lampiran` (bagian 4).

### `GET /public/pengumuman/{slug}/lampiran` (baru, publik)

Mengalirkan file lampiran pengumuman yang `is_publik` dan sudah terbit, dengan `Content-Type` sesuai file dan `Content-Disposition: inline; filename="<lampiran_nama>"`. Pengumuman tidak ada, belum terbit, bukan publik, atau tanpa lampiran dibalas 404 `NOT_FOUND`. Rate limit sama dengan endpoint publik lain.

Alamat ini tidak kedaluwarsa. Halaman publik FE di-cache (ISR 5 menit), jadi signed URL 30 menit bisa sudah kedaluwarsa saat pengunjung membukanya.

## 4. Bentuk `lampiran` di respons

```json
"lampiran": { "nama": "Surat Edaran Libur Semester.pdf", "jenis": "pdf", "ukuran": 284512, "url": "…" }
```

`lampiran` bernilai `null` kalau pengumuman tidak punya lampiran.

- `jenis`: `pdf` untuk file `.pdf`, selain itu `gambar` (selalu JPEG). FE memakainya untuk memilih tampilan (pratinjau gambar atau kartu dokumen).
- `ukuran`: byte, dari `lampiran_ukuran`.
- `url` di `PengumumanResource` (`GET /pengumuman`, `GET /pengumuman/{id}`, respons `POST`/`PUT`, `pengumuman_terbaru` di `GET /dashboard`): signed URL `GET /media/{token}` 30 menit dari `urlPrivat()`, sesuai aturan "File private" A7.
- `url` di `PengumumanPublikResource` (`GET /public/pengumuman`, `GET /public/pengumuman/{slug}`): URL absolut `GET /public/pengumuman/{slug}/lampiran`.

## 5. Yang tidak berubah

- Notifikasi `pengumuman_baru` tidak menyebut lampiran.
- Sasaran, aturan publik, pin, dan draft tetap sama.

## 6. Uji backend yang diharapkan

Ditulis di `tests/Feature/Pengumuman/LampiranPengumumanTest.php` (branch backend `be/lampiran-pengumuman`).

- penulis bisa menambah lampiran PDF saat membuat pengumuman
- lampiran gambar dikecilkan menjadi JPEG dan ekstensi namanya ikut berubah
- file selain PDF dan gambar ditolak, begitu juga file di atas 5 MB
- mengganti lampiran menghapus file lama
- `hapus_lampiran` menghapus file dan mengosongkan ketiga kolom
- `hapus_lampiran` bersama `lampiran` ditolak
- wali murid di luar sasaran tidak bisa membuka pengumuman maupun lampirannya
- lampiran pengumuman publik yang sudah terbit bisa diunduh tanpa login; pengumuman bukan publik atau draft dibalas 404
- menghapus pengumuman menghapus file lampirannya
- body JSON tanpa lampiran tetap diterima seperti sebelumnya

## 7. FE setelah backend siap

- Jalankan `npm run gen:api` dari `api.json` backend yang baru.
- Form pengumuman: kolom "Lampiran (opsional)" memakai `ZonaUnggah` (satu file, PDF/JPG/PNG/WebP, 5 MB). Saat mengubah, lampiran yang ada tampil dengan tombol Hapus Lampiran dan bisa diganti. Form dikirim multipart lewat `keFormData`.
- Detail pengumuman (wali, guru dan Kepala Sekolah, publik): kartu lampiran berisi nama, ukuran, dan tombol Buka Lampiran ke tab baru. Lampiran gambar tampil sebagai pratinjau.
- Daftar pengumuman: penanda lampiran (ikon klip) di kartu yang punya lampiran.
