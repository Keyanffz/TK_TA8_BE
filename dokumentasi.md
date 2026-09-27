# Dokumentasi Backend TK Tarbiyathul Athfal 8

REST API untuk sistem informasi TK Tarbiyathul Athfal 8. Dipakai oleh frontend Next.js dan nanti aplikasi Flutter. Acuan desain dan kontrak API: `PROMPT_BE_TK.md` (Bagian A sama persis dengan `PROMPT_FE_TK.md` di repo FE).

## Status

| Fase | Status |
|---|---|
| 0. Analisis | Selesai, rencana disetujui |
| 1. Fondasi | Selesai |
| 2. Database | Selesai |
| 3. Auth & akun | Selesai |
| 4. Master akademik | Selesai, disetujui (dengan revisi) |
| 5. Keuangan | Selesai, disetujui (dengan revisi) |
| 6. Akademik & komunikasi | Selesai, disetujui (dengan revisi) |
| 7. PPDB, CMS, dashboard | Selesai, disetujui (dengan revisi) |
| 8. Hardening | Selesai, menunggu review |

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
| Jenis tagihan | `GET /jenis-tagihan` (K), `POST /jenis-tagihan`, `PUT/DELETE /jenis-tagihan/{id}` (SA) |
| Keringanan (K) | `GET/POST /keringanan`, `PUT/DELETE /keringanan/{id}` |
| Tagihan | `GET /tagihan`, `GET /tagihan/{id}` (K semua, G murid kelasnya, W anak sendiri), `POST /tagihan` (K), `POST /tagihan/generate`, `PATCH /tagihan/{id}/batalkan` (SA) |
| Pembayaran | `POST /tagihan/{id}/pembayaran` (W bukti transfer, K tunai atau transfer), `GET /pembayaran`, `GET /pembayaran/{id}`, `GET /pembayaran/{id}/bukti`, `GET /pembayaran/{id}/kwitansi` (K, W sendiri), `POST /pembayaran/{id}/terima`, `POST /pembayaran/{id}/tolak` (K) |
| Laporan (K) | `GET /laporan/keuangan`, `GET /laporan/keuangan/export`, `GET /laporan/tunggakan` |
| Kegiatan kelas | `GET /kegiatan`, `GET /kegiatan/{id}` (SA, G kelas diampu, W kelas anak), `POST /kegiatan`, `PUT/DELETE /kegiatan/{id}`, `POST /kegiatan/{id}/foto`, `DELETE /kegiatan-foto/{id}` (G pembuat, SA) |
| Elemen penilaian | `GET /elemen-penilaian` (SA, G), `POST /elemen-penilaian`, `PUT/DELETE /elemen-penilaian/{id}` (SA) |
| Rapor | `GET /rapor`, `GET /rapor/{id}`, `GET /rapor/{id}/pdf` (SA, G kelas diampu, W rapor terbit anaknya), `POST /rapor` (G, SA; hanya kelas yang diampu), `PUT /rapor/{id}`, `POST /rapor/{id}/detail/{detail_id}/foto`, `POST /rapor/{id}/ajukan` (guru pembuat), `POST /rapor/{id}/terbitkan`, `POST /rapor/{id}/revisi` (SA) |
| Pengumuman | `GET /pengumuman`, `GET /pengumuman/{id}` (feed per role), `POST /pengumuman` (SA, G), `PUT/DELETE /pengumuman/{id}` (penulis, SA) |
| Agenda | `GET /agenda?bulan=` (semua), `POST /agenda`, `PUT/DELETE /agenda/{id}` (SA) |
| Notifikasi | `GET /notifikasi`, `GET /notifikasi/belum-dibaca`, `POST /notifikasi/{id}/baca`, `POST /notifikasi/baca-semua` (semua) |
| Publik (tanpa login) | `GET /public/profil`, `GET /public/pengumuman`, `GET /public/pengumuman/{slug}`, `GET /public/agenda`, `GET /public/galeri`, `GET /public/galeri/{slug}`, `GET /public/guru`, `GET /public/ppdb` |
| Dashboard | `GET /dashboard` (payload per role, W boleh `?murid_id=`) |
| PPDB | `GET /pendaftaran`, `GET /pendaftaran/{id}` (SA semua, W miliknya), `POST /pendaftaran` (W), `POST /pendaftaran/{id}/verifikasi`, `POST /pendaftaran/{id}/terima`, `POST /pendaftaran/{id}/tolak` (SA) |
| Pengaturan | `GET /pengaturan?grup=` (SA; K hanya `grup=keuangan`), `PUT /pengaturan`, `POST /pengaturan/upload` (SA) |
| Galeri (SA) | `GET/POST /galeri-album`, `GET/PUT/DELETE /galeri-album/{id}`, `POST /galeri-album/{id}/foto`, `PUT/DELETE /galeri-foto/{id}` |
| Log aktivitas (SA) | `GET /log-aktivitas` |

K = petugas keuangan (Kepala Sekolah atau guru `bisa_kelola_keuangan`), dijaga middleware `can:kelola-keuangan`.

Semua endpoint kecuali `GET /health` dan `GET /media/{token}` dibatasi 120 request per menit (per user kalau sudah login, per IP kalau belum).

Command (bisa dijalankan manual, semua punya `--dry-run`): `tagihan:generate [--periode=YYYY-MM]` (tanggal 1 pukul 00:10), `tagihan:tandai-terlambat` (harian 00:30), `tagihan:pengingat` (harian 07:00), `kode-tautan:bersihkan` (harian 01:00). Jam dalam WIB.

## Keputusan menunggu review

Keputusan kecil yang diambil tanpa menunggu konfirmasi karena tidak mengubah kontrak A7 atau skema A4. Mohon ditinjau; yang tidak disetujui akan diubah.

Fase 8:

1. Rate limit: limiter `api` 120 request per menit, dihitung per user untuk request yang sudah login dan per IP untuk endpoint publik serta endpoint auth tanpa login (keduanya berbagi kuota per IP). **Catatan review:** karena FE memakai pola BFF, IP yang dipakai semua limiter berbasis IP (`api`, `login`, `login-google`) adalah IP klien dari `X-Forwarded-For` kalau request datang dari proxy di `TRUSTED_PROXIES` (middleware `TrustProxies` bawaan Laravel berjalan sebelum limiter, jadi tidak perlu kode tambahan). Diuji di `tests/Feature/Hardening/RateLimitProxyTest.php`; syarat deploy ada di "Instalasi dan menjalankan". `GET /health` dan `GET /media/{token}` tidak dibatasi: health dipanggil pemantau, dan satu halaman kegiatan bisa memuat puluhan foto lewat signed URL dari satu IP sekolah. Limiter khusus (`login`, `login-google`, `tautkan-anak`) tetap berlaku di atasnya.
2. Endpoint detail yang Policy `view`-nya hanya membalas 404 (murid, kelas, tagihan, kegiatan, rapor dan PDF-nya, pengumuman, pendaftaran) memakai `App\Support\Jangkauan::pastikanTerlihat()` alih-alih `Gate::authorize('view', …)`. Perilakunya sama (404 "Data tidak ditemukan."), tetapi OpenAPI tidak lagi mencantumkan 403 `FORBIDDEN` di endpoint tanpa middleware role. Pembayaran tetap memakai `Gate::authorize` karena guru tanpa izin keuangan memang mendapat 403.
3. OpenAPI: `ResponsFileExtension` membuang entri `application/json` kosong di respons file; `GET /media/{token}` bertipe `application/octet-stream` (isinya JPEG atau PDF); detail, bukti, dan kwitansi pembayaran mencantumkan 403; semua `*_url` file yang bisa kosong bertipe `string | null` lewat anotasi `@var` di Resource; `items` pada `PUT /pengaturan` bertipe objek.
4. Grup route `/public` dipindah ke akhir `routes/api.php`. Kalau `GET /public/pengumuman` dianalisis Scramble lebih dulu, item array bertingkat di Resource lain (`foto` kegiatan dan galeri, `wali` murid, `detail` rapor, `dokumen` PPDB, `kelas`/`murid` pengumuman, `anak` dan `kelas_diampu` di `/auth/me`) terbaca sebagai `string`. Masalah ini sudah ada sejak commit galeri Fase 7 (`73303be`) dan ikut di `api.json` Fase 7; ditemukan dengan membandingkan ekspor per commit. Test `DokumentasiApiTest` sekarang memeriksa beberapa tipe bertingkat supaya tidak terulang.
5. Proxy: `config/trustedproxy.php` membaca `TRUSTED_PROXIES` (IP/CIDR dipisah koma, atau `*`). Kosong berarti header `X-Forwarded-*` tidak dipercaya. Di balik reverse proxy HTTPS nilai ini harus diisi supaya signed URL file private memakai host dan skema yang benar.
6. Audit N+1: `tests/Feature/Hardening/AuditQueryTest.php` mengisi data demo lalu membandingkan jumlah query setiap daftar berpaginasi (28 kombinasi role dan endpoint) antara 8 dan 20 baris. Semua sama, jadi tidak ada perbaikan kode. Pembanding 1–3 baris sempat membuat `/pembayaran` tampak bertambah satu query, karena Laravel melewati eager load `pembayar` ketika semua baris tunai (kuncinya null); itu bukan N+1.
7. Index: semua kolom yang sering difilter sudah ber-index sejak Fase 2 (status, periode, jatuh tempo, tanggal bayar, `published_at`, `tanggal` kegiatan, `tanggal_mulai` agenda, `is_publik` galeri, semua foreign key, dan unique di A4). Tidak ada migration index baru. `murid.nik` dan `pendaftaran.nik` tidak ber-index; keduanya hanya dicari sekali saat pendaftaran PPDB dan datanya puluhan baris.
8. `composer phpstan` = `phpstan analyse --memory-limit=1G`, dipakai sebagai perintah pengecekan biasa.

## Keputusan Fase 6–7 (sudah direview)

Disetujui pemilik repo setelah Fase 7, kecuali tiga hal yang diubah (nomor 7, 19, 22; lihat "Revisi setelah review Fase 6–7" di Changelog).

Fase 6:

1. `be/fase-6-8` dibuat dari `main` (Fase 3); sebelum mulai, branch ini di-fast-forward ke `be/fase-4-5`.
2. Enum `JenisNotifikasi` (15 nilai A7, termasuk `tagihan_tertunda`) dipakai semua kelas notifikasi dan terdokumentasi sebagai enum di OpenAPI. Tidak mengubah nilai.
3. Notifikasi: `GET /notifikasi` berpaginasi, terbaru dulu, dengan `filter[dibaca]=0|1`. `POST /notifikasi/baca-semua` membalas `{ jumlah }` (yang baru ditandai). `{id}` notifikasi berupa UUID (id tabel `notifications`); id bukan UUID atau milik orang lain dibalas 404.
4. Agenda: `GET /agenda` tidak berpaginasi, `bulan` bawaan bulan ini, dan berisi agenda yang bersinggungan dengan bulan itu (agenda lintas bulan muncul di kedua bulan). Semua pengguna yang masuk melihat semua agenda, termasuk yang `is_publik = false`. `is_publik` bawaan `false`.
5. Elemen penilaian: `GET` tidak berpaginasi dan memuat elemen nonaktif (ada `is_aktif`). `kode` diubah ke huruf besar dan hanya boleh huruf, angka, garis bawah. Tanpa `urutan`, elemen baru ditaruh paling akhir. Hapus ditolak `BUSINESS_RULE` kalau elemen sudah dipakai di rapor. Elemen baru atau yang dinonaktifkan tidak mengubah rapor yang sudah dibuat.
6. Kegiatan kelas: guru hanya untuk kelas yang dia ampu di tahun ajaran aktif (selain itu 422 di `kelas_id`); Kepala Sekolah untuk kelas mana pun, tercatat atas profil gurunya. `tanggal` tidak boleh di masa depan. `PUT` hanya mengubah tanggal, tema, judul, deskripsi (`kelas_id` dan `foto` ditolak). Maksimal 10 foto per unggahan dan 30 per kegiatan. `caption` foto belum bisa diisi lewat API (A7 tidak punya field-nya), jadi `null` kecuali data demo. Guru pengampu lain di kelas yang sama bisa melihat tetapi mendapat 403 saat mengubah. Parameter daftar: `filter[kelas_id]`, `search` (judul, tema), `sort=tanggal|created_at` (bawaan `-tanggal`).
7. Rapor, pembuat: **Revisi:** Kepala Sekolah boleh `POST /rapor` hanya untuk murid di kelas yang dia ampu (wali kelas atau pendamping) di tahun ajaran aktif, sama seperti guru, tercatat atas profil gurunya. Mengisi, mengunggah foto, dan mengajukan hanya oleh guru pembuat (Kepala Sekolah yang bukan pembuat mendapat 403). Kelas rapor = kelas murid dengan penempatan `aktif` di tahun ajaran aktif; semester 1 atau 2 bebas dipilih.
8. Rapor, isi: `tinggi_badan` 50–200 cm dan `berat_badan` 5–80 kg, satu desimal. `detail` di `PUT` boleh sebagian; elemen yang tidak ada di rapor ditolak 422. Mengajukan ditolak `BUSINESS_RULE` kalau ada elemen yang deskripsinya kosong; tinggi, berat, dan catatan guru tidak wajib. Foto per elemen menggantikan foto lama; tidak ada endpoint hapus foto rapor (tidak ada di A7).
9. Rapor, review: `catatan_revisi` tetap tersimpan setelah diajukan ulang atau terbit, dan tidak dikirim ke wali murid. Rapor terbit tidak bisa diubah atau ditarik lagi. `GET /rapor/{id}/pdf` untuk Kepala Sekolah dan guru bisa dipakai sebelum terbit sebagai pratinjau (PDF bertanda "Pratinjau"). Nama file `rapor-{nis}-{tahun-ajaran}-semester-{n}.pdf`. Parameter daftar: `filter[kelas_id|semester|status|tahun_ajaran_id|murid_id]`, `search` (nama/NIS murid), `sort=updated_at|diajukan_at|created_at` (bawaan `-updated_at`).
10. Notifikasi rapor: `rapor_diajukan` ke Kepala Sekolah aktif, `rapor_revisi` ke guru pembuat, `rapor_terbit` ke semua wali murid anak itu; semua ber-url `/dashboard/rapor/{id}`.
11. Pengumuman, penerima notifikasi `pengumuman_baru` (akun aktif, selain penulis): `semua` = semua guru dan wali murid; `guru`; `wali_murid`; `kelas` = wali murid yang anaknya berpenempatan aktif di kelas itu ditambah wali kelas dan guru pendampingnya; `murid` = wali murid anak itu ditambah guru pengampu kelasnya di tahun ajaran aktif. Kepala Sekolah tidak dikirimi karena melihat semua pengumuman. Judul notifikasi = judul pengumuman, pesan = 140 karakter pertama isi tanpa HTML, url `/dashboard/pengumuman/{id}`.
12. Pengumuman, terbit: notifikasi dikirim saat pengumuman berubah dari draft menjadi terbit (`published_at` kosong → terisi). Mengubah pengumuman yang sudah terbit tidak mengirim ulang dan `published_at` tetap. `publish: false` pada pengumuman terbit menariknya kembali jadi draft (`published_at` dikosongkan); kalau diterbitkan lagi, notifikasi terkirim lagi.
13. Pengumuman, data: slug dari judul dengan akhiran `-2`, `-3` kalau sudah dipakai (termasuk pengumuman terhapus) dan tidak berubah saat judul diganti. `lampiran_path` (kolom A4) belum dipakai karena body A7 tidak punya field lampiran. `kelas` dan `murid` (daftar sasaran) hanya dikirim ke Kepala Sekolah dan penulis, supaya wali tidak melihat nama anak lain. Urutan feed: disematkan dulu, lalu `published_at` (draft: `created_at`) terbaru. Parameter: `filter[target]`, `filter[terbit]=0|1`, `search` (judul). Hapus = soft delete. Guru lain yang melihat pengumuman di feed mendapat 403 saat mengubah.
14. Di OpenAPI, `GET /kegiatan/{id}`, `GET /rapor/{id}`, `GET /pengumuman/{id}`, dan `GET /rapor/{id}/pdf` masih mencantumkan 403 `FORBIDDEN`. **Selesai di Fase 8** (lihat keputusan Fase 8 nomor 2).

Fase 7:

15. Pengaturan, simpan: `PUT /pengaturan` hanya mengubah kunci yang dikirim. Kunci di luar daftar A4 ditolak 422 di field `items`. Kesalahan per kunci memakai nama kunci sebagai field error (misalnya `profil.nama_sekolah`, `landing.program.0.judul`), tanpa awalan `items.`. Kunci angka disimpan sebagai integer walau dikirim sebagai string. Kunci HTML (`profil.sejarah`, `profil.sambutan_kepsek`, `ppdb.info`) disanitasi. Log aktivitas `pengaturan` mencatat nama kunci yang diubah, bukan nilainya.
16. Pengaturan, batas nilai: `profil.npsn` 8 digit, `profil.maps_embed_url` harus `https`, `keuangan.hari_pengingat` 1–14, `ppdb.kuota` 0–1000, `keuangan.rekening` paling banyak 5, daftar (misi, program, fasilitas, keunggulan) paling banyak 20, `ikon` huruf kecil/angka/tanda hubung (nama ikon lucide). `ppdb.tanggal_tutup` tidak boleh sebelum `ppdb.tanggal_buka`, dibandingkan juga dengan nilai yang sudah tersimpan. `ppdb.dibuka = true` tanpa tahun ajaran tujuan ditolak `BUSINESS_RULE`.
17. Pengaturan, gambar: `POST /pengaturan/upload` menyimpan ke disk public folder `pengaturan/`. Field gambar di `PUT` harus path dari folder itu yang filenya ada. Gambar yang tidak dipakai lagi setelah `PUT` dihapus dari disk; gambar yang diunggah tetapi tidak pernah disimpan ke pengaturan tetap tertinggal (tidak ada pembersihan otomatis).
18. Pengaturan, akses dan cache: guru berizin keuangan wajib mengirim `grup=keuangan` (tanpa itu 403). Semua kunci dibaca sekali lalu disimpan di cache tanpa batas waktu; cache dibuang lewat event `saved`/`deleted` model `Pengaturan`, jadi perubahan dari seeder atau Tinker juga langsung terbaca.
19. Galeri: **Revisi:** `GET /galeri-album/{id}` (Kepala Sekolah) mengembalikan album beserta semua foto, termasuk album yang belum publik; `GET /galeri-album` hanya berisi `cover_url` dan `jumlah_foto`. Respons `POST`/`PUT /galeri-album` dan `POST /galeri-album/{id}/foto` memakai bentuk detail (dengan `foto`). `cover` opsional (multipart); tanpa sampul, `cover_url` memakai foto dengan urutan terkecil. Album baru tidak publik kecuali `is_publik` dikirim. Slug album tetap walau judul diganti. Maksimal 20 foto per unggahan, tanpa batas total. `PUT /galeri-foto/{id}` menerima `caption` dan `urutan` dan membalas `{ id, caption, urutan }`. Menghapus foto yang sedang jadi sampul mengosongkan `cover_path`.
20. Publik: `/public/pengumuman` hanya `is_publik` yang `published_at`-nya sudah lewat, urut disematkan lalu terbaru, tanpa penulis dan sasaran. `/public/guru` berisi guru berakun aktif dengan `tampil_di_landing` (termasuk profil Kepala Sekolah), Kepala Sekolah lebih dulu lalu urut nama; bentuknya `{ id, nama, jabatan, foto_url }`. `/public/galeri` berpaginasi; `/public/agenda` memakai bentuk yang sama dengan `GET /agenda`.
21. `/public/ppdb`: `{ dibuka, tanggal_buka, tanggal_tutup, kuota, sisa_kuota, info, tahun_ajaran }`. `dibuka` sudah memperhitungkan tanggal buka/tutup dan keberadaan tahun ajaran tujuan, tetapi tidak memperhitungkan kuota (lihat `sisa_kuota`). `kuota = 0` berarti tidak ada tempat.
22. PPDB, pendaftaran: dokumen dikirim per jenis sebagai field multipart `akta_kelahiran` dan `kartu_keluarga` (gambar atau PDF, wajib), `pas_foto` (gambar, wajib), dan `lainnya[]` (opsional, maksimal 3). `nik` wajib 16 digit karena kolomnya tidak nullable di A4. **Revisi:** pendaftaran ditolak `BUSINESS_RULE` kalau NIK anak sudah punya pendaftaran selain `ditolak` (di tahun ajaran mana pun) atau sudah dipakai murid (yang tidak di-soft delete); pendaftar yang pernah ditolak boleh mendaftar ulang. Profil wali tidak harus lengkap untuk mendaftar. Kode `PPDB-{tahun mulai tahun ajaran}-XXXX`. Parameter daftar: `filter[status|tahun_ajaran_id|tingkat_tujuan]`, `search` (kode, nama anak), `sort=created_at|nama`.
23. PPDB, keputusan: `verifikasi` hanya dari `diajukan`; `terima` hanya dari `diverifikasi`; `tolak` dari `diajukan` atau `diverifikasi`, alasannya disimpan di `catatan`. `kelas_id` saat menerima harus kelas di tahun ajaran tujuan dan dicek kapasitasnya; kalau gagal, seluruh penerimaan dibatalkan. Ketiga keputusan dicatat di activity log `ppdb` dan mengirim `pendaftaran_diproses` ke wali pendaftar (url `/dashboard/ppdb/{id}`); `pendaftaran_baru` dikirim ke Kepala Sekolah aktif.
24. PPDB, murid baru: NIS memakai tahun `tanggal_mulai` tahun ajaran tujuan, `tanggal_masuk` = `tanggal_mulai` itu, pas foto disalin (kalau berupa gambar) ke folder `murid/`, dan wali pendaftar menjadi kontak utama. Nama dan pekerjaan orang tua dari formulir tidak disalin ke murid karena tabel murid tidak punya kolomnya.
25. Dashboard Kepala Sekolah: `guru_aktif` = akun guru berstatus aktif, `kelas` = kelas di tahun ajaran aktif, `wali_murid` = akun wali murid berstatus aktif. `keuangan_bulan_ini` memakai aturan laporan keuangan (tagihan menurut bulan jatuh tempo, tanpa yang dibatalkan). `grafik_pemasukan` 12 bulan sampai bulan ini dari pembayaran `diterima` menurut `tanggal_bayar`. `pendaftaran_baru` = pendaftaran berstatus `diajukan`. Daftar terbaru dan mendatang berisi paling banyak 5; `pengumuman_terbaru` hanya yang sudah terbit, `agenda_mendatang` agenda yang belum selesai.
26. Dashboard guru: `progres_rapor.total` = jumlah murid berpenempatan aktif di kelas yang diampu; keempat status menghitung rapor semester aktif, jadi yang belum dibuat = total dikurangi jumlah keempatnya. `keuangan_kelas` menghitung jumlah tagihan (bukan rupiah) murid kelasnya yang jatuh tempo bulan ini; `belum` mencakup belum bayar, menunggu verifikasi, dan terlambat.
27. Dashboard wali: tanpa `murid_id`, anak pertama menurut nama panggilan. `anak` memakai bentuk `GET /wali/anak`. `tagihan_aktif` = tagihan berstatus belum bayar, menunggu verifikasi, atau terlambat, urut jatuh tempo; `total_belum_bayar` tidak menghitung yang menunggu verifikasi. `kegiatan_terbaru` dari semua kelas yang pernah diikuti anak itu. Wali tanpa anak tertaut mendapat `anak: null`, daftar kosong, dan `rapor_terbaru: null`.
28. Log aktivitas: `{ id, jenis, event, deskripsi, pelaku { id, nama, role } | null, subjek { tipe, id } | null, properti, created_at }`, berpaginasi, terbaru dulu. Filter `filter[user_id]`, `filter[jenis]` (salah satu `akun, guru, wali, tagihan, pembayaran, rapor, ppdb, pengaturan`), `filter[tanggal]`.

## Keputusan Fase 4–5 (sudah direview)

Disetujui pemilik repo setelah Fase 5, kecuali empat hal yang diubah (lihat "Revisi setelah review Fase 4–5" di Changelog). Nomor 5, 15, 20, dan 22 di bawah sudah memuat keputusan setelah revisi.

Fase 4:

1. `GET /tagihan` dan `GET /tagihan/{id}` (baca saja, dengan scope B4 dan Policy) dibuat di Fase 4, bukan Fase 5, karena Policy dan scope diminta berlaku untuk semua endpoint murid, kelas, dan tagihan di Fase 4. Detail tagihan sudah berisi riwayat pembayaran dan `rekening` sekolah dari `keuangan.rekening`.
2. Tahun ajaran pertama yang dibuat langsung aktif; tahun ajaran berikutnya dibuat tidak aktif. `is_aktif` tidak diterima di `POST`/`PUT /tahun-ajaran`; satu-satunya jalan mengubahnya `POST /tahun-ajaran/{id}/aktifkan`. `semester_aktif` opsional (bawaan 1).
3. `DELETE /tahun-ajaran/{id}` ditolak `BUSINESS_RULE` kalau tahun ajaran sedang aktif, sudah punya kelas, tagihan, jenis tagihan, atau pendaftar PPDB, atau dipakai di `ppdb.tahun_ajaran_id`.
4. Kapasitas kelas dan `jumlah_murid` hanya menghitung penempatan berstatus `aktif`. `PUT /kelas/{id}` menolak kapasitas di bawah jumlah itu, dan menolak mengganti tahun ajaran kalau kelas sudah berisi murid. `DELETE /kelas/{id}` ditolak kalau kelas sudah punya murid, kegiatan, atau rapor. Wali kelas dan guru pendamping harus guru berakun aktif (profil guru Kepala Sekolah boleh) dan tidak boleh orang yang sama.
5. `DELETE /kelas/{id}/murid/{murid_id}` menghapus baris penempatan (untuk memperbaiki salah penempatan), bukan memberi status `keluar`. Murid yang keluar sekolah diubah lewat `PUT /murid/{id}`. **Revisi:** ditolak `BUSINESS_RULE` kalau murid sudah punya rapor di kelas itu (rapor di kelas lain tidak menghalangi).
6. Kenaikan kelas: tahun ajaran asal = tahun ajaran aktif, tujuan harus berbeda. Setiap murid harus aktif dan punya penempatan `aktif` di tahun ajaran asal, dan belum punya kelas di tahun ajaran tujuan. Tingkat kelas tujuan tidak dicek (naik dari A ke B tidak dipaksa). Murid `lulus` mendapat `status = lulus` dan `tanggal_keluar` = `tanggal_selesai` tahun ajaran asal. Respons `{ naik, tinggal, lulus }` (jumlah per status). Tidak dicatat di activity log karena tidak ada di daftar B7.
7. `POST /murid` tidak menerima `status` (selalu `aktif`). `PUT /murid/{id}` mewajibkan `status`; `tanggal_keluar` wajib untuk `lulus`/`pindah`/`keluar` dan dikosongkan untuk `aktif`. Perubahan status ikut mengubah penempatan di tahun ajaran aktif: `lulus` → `lulus`, `pindah`/`keluar` → `keluar`, kembali `aktif` → penempatan dibuka lagi (dengan cek kapasitas). NIS tidak bisa diubah.
8. `DELETE /murid/{id}` hanya untuk data salah input: ditolak kalau murid sudah punya tagihan, rapor, atau data PPDB. Murid di-soft delete, penempatannya dihapus, dan kode tautannya dikosongkan.
9. Isi `MuridResource` sama untuk semua yang boleh melihat murid (Kepala Sekolah, guru pengampu, wali anak itu), termasuk NIK, alamat, dan `catatan_khusus`. Detail murid berisi `wali[]` (`id, nama, email, no_hp, hubungan, is_kontak_utama, tertaut_at`), sehingga ayah dan ibu saling melihat kontak masing-masing. `kode_tautan` dan `kode_tautan_expired_at` hanya untuk Kepala Sekolah.
10. Kode tautan hanya dibuat untuk murid aktif; kode baru menggantikan kode lama. `kode-tautan:bersihkan` dijadwalkan harian pukul 01:00 WIB (B6.2 tidak menyebut jam).
11. Melepas wali yang menjadi kontak utama memindahkan kontak utama ke wali yang paling awal tertaut. Wali yang dilepas tidak diberi notifikasi (tidak ada jenis notifikasi untuk itu di A7).
12. `bukti_url` pembayaran hanya diisi untuk petugas keuangan dan wali murid; guru tanpa izin keuangan melihat riwayat pembayaran murid kelasnya dengan `bukti_url: null`.
13. Parameter daftar di luar yang disebut A7: `GET /murid` `sort=nama|nis|created_at`; `GET /kelas` `sort=nama|created_at`; `GET /tahun-ajaran` `sort=nama|tanggal_mulai`; `GET /tagihan` `search` (kode tagihan, nama murid) dan `sort=jatuh_tempo|periode|created_at`. Semua daftar berpaginasi (bawaan 15, maksimal 100).
14. Di OpenAPI, `GET /murid/{id}` dan `GET /tagihan/{id}` masih mencantumkan 403 `FORBIDDEN` karena Scramble membaca pemanggilan `Gate::authorize`. **Selesai di Fase 8.**

Fase 5:

15. Generate bulanan (manual maupun scheduler) hanya untuk bulan di dalam rentang tahun ajaran aktif; bulan pertama dan terakhir ikut walau tahun ajaran tidak mulai tanggal 1 (TA 13 Juli 2026 – 25 Juni 2027 → Juli 2026 s.d. Juni 2027). Di luar itu ditolak `BUSINESS_RULE`, dan command keluar dengan kode gagal. Akibatnya, kalau tahun ajaran baru belum diaktifkan saat 1 Juli, tagihan Juli harus dibuat manual setelah diaktifkan. **Revisi:** saat itu terjadi pada jadwal (`tagihan:generate` tanpa `--periode` dan tanpa `--dry-run`), Kepala Sekolah aktif mendapat notifikasi `tagihan_tertunda` (url `/dashboard/tahun-ajaran`), termasuk saat belum ada tahun ajaran aktif sama sekali. `--periode`, `--dry-run`, dan `POST /tagihan/generate` tidak mengirim notifikasi karena pesannya sudah terlihat oleh yang menjalankan.
16. Keringanan untuk tagihan bulanan dipakai kalau masa berlakunya menyentuh bulan periode (bukan hanya tanggal 1); untuk tagihan sekali, yang berlaku pada tanggal pembuatan. Kalau ada lebih dari satu (satu berakhir dan satu mulai di bulan yang sama), yang mulai paling akhir dipakai. Menambah, mengubah, atau menghapus keringanan, dan mengubah nominal jenis tagihan, tidak menghitung ulang tagihan yang sudah ada.
17. `dibuat_oleh` tagihan bulanan: id Kepala Sekolah kalau lewat `POST /tagihan/generate`, `null` (sistem) kalau dari scheduler. Tagihan dengan total 0 (keringanan penuh) langsung `lunas` dan tidak dikirimi notifikasi `tagihan_baru`.
18. `POST /tagihan`: `murid_ids` dan `kelas_id` tidak boleh dikirim bersamaan; `kelas_id` berarti murid dengan penempatan aktif di kelas itu. Selain murid yang sudah punya tagihan jenis itu, murid tidak aktif dan murid yang tingkat kelasnya tidak sesuai `jenis_tagihan.tingkat` juga dihitung sebagai `dilewati`. `jatuh_tempo` minimal hari ini. Jenis tagihan harus berperiode `sekali` dan aktif.
19. Jenis tagihan: tahun ajaran dan periode tidak bisa diganti setelah dipakai di tagihan; hapus ditolak kalau sudah dipakai di tagihan atau keringanan (disarankan menonaktifkan). Nominal maksimal Rp 100.000.000 untuk mencegah salah ketik.
20. `POST /tagihan/{id}/pembayaran`: wali selalu transfer dengan bukti gambar wajib (status `menunggu`). **Revisi:** petugas keuangan mengirim `metode: tunai` atau `metode: transfer`; keduanya langsung `diterima` dan tagihan lunas. Untuk transfer, `bukti`, `bank_pengirim`, dan `nama_pengirim` opsional; untuk tunai ketiganya ditolak validasi. Activity log `tunai_dicatat` / `transfer_dicatat`. `tanggal_bayar` tidak boleh di masa depan. Pembayaran yang dicatat petugas `dibayar_oleh = null`. Kode `PAY-YYYYMMDD` memakai tanggal pembayaran dicatat, bukan `tanggal_bayar`.
21. Akses guru tanpa izin keuangan: `GET /pembayaran` 403; `GET /pembayaran/{id}` (dan bukti, kwitansi) 403 untuk pembayaran murid kelasnya, 404 untuk lainnya; `POST /tagihan/{id}/pembayaran` sama. Urutannya: data di luar jangkauan selalu 404 dulu.
22. **Revisi:** `url` semua notifikasi tagihan dan pembayaran, untuk semua role, adalah `/dashboard/tagihan/{tagihan_id}` (peta route FE B4 tidak punya halaman detail pembayaran), termasuk `pembayaran_masuk` untuk petugas keuangan. `pembayaran_masuk` dikirim ke Kepala Sekolah dan guru berizin keuangan yang akunnya aktif; notifikasi tagihan dikirim ke semua wali yang tertaut.
23. Pengingat hanya untuk tagihan `belum_bayar` yang jatuh tempo tepat H-`keuangan.hari_pengingat`; penandaan terlambat hanya untuk `belum_bayar` (tagihan `menunggu_verifikasi` dilewati). Menjalankan `tagihan:pengingat` dua kali di hari yang sama mengirim dua kali.
24. Laporan keuangan: tagihan dikelompokkan menurut bulan `jatuh_tempo`, tagihan dibatalkan tidak dihitung, `terbayar` = total tagihan `lunas`, `pemasukan` = pembayaran `diterima` menurut `tanggal_bayar`, `persen_lunas` satu desimal. Bentuk respons: `{ dari, sampai, ringkasan, per_jenis[], per_bulan[] }` (field per bagian di OpenAPI). Rentang maksimal dua tahun. Ekspor `.xlsx` berisi sheet "Tagihan" dan "Pembayaran Diterima" dan tidak memakai `kelas_id` (A7 hanya menyebut `dari` dan `sampai`).
25. Tunggakan = tagihan berstatus `terlambat`, dikelompokkan per murid (urut total terbesar) dengan `kontak_wali` (kontak utama) untuk ditindaklanjuti; tidak berpaginasi.
26. `GET /pembayaran`: `filter[tanggal]` = tanggal bayar persis (YYYY-MM-DD), `search` mencari kode pembayaran dan nama murid.
27. Kwitansi: A5 mendatar, kop dari `profil.nama_sekolah`, `profil.alamat`, `profil.telepon`, `profil.email`, dan `profil.logo` (jika ada filenya), tanpa terbilang, dengan waktu cetak.
28. `phpunit.xml` memasang `memory_limit=512M` untuk test. PHP CLI di Arch bawaannya 128 MB (di container Ubuntu tanpa batas), dan seluruh suite (termasuk pembuatan dokumentasi OpenAPI) melewati 128 MB.
29. Di OpenAPI, respons file (`bukti`, `kwitansi`, `export`) sudah bertipe media yang benar, tetapi Scramble masih menambahkan entri `application/json` kosong; `bukti` dan `kwitansi` belum mencantumkan 403. **Selesai di Fase 8.**

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
composer phpstan
composer check:slop
```

Hasil saat serah terima: 190 test lulus; Pint, PHPStan, dan `check:slop` tanpa temuan.

- Test memakai SQLite in-memory dari `phpunit.xml`, jadi tidak menyentuh database lokal. Untuk menjalankan test ke MariaDB, buat database terpisah lalu timpa lewat variabel environment, misalnya `DB_CONNECTION=mariadb DB_DATABASE=tk_test php artisan test`; variabel environment shell mengalahkan nilai di `phpunit.xml`. Jangan arahkan ke database utama, karena `RefreshDatabase` mengosongkannya.
- Kalau `php artisan config:cache` pernah dijalankan, test ikut membaca konfigurasi yang di-cache. Jalankan `php artisan config:clear`, atau pakai `composer test` yang membersihkannya lebih dulu.

### Queue dan scheduler

- `php artisan dev` menjalankan server di port 8000, `queue:listen --tries=1`, dan `pail` (butuh `pcntl`). Tanpa `pcntl`, jalankan `php artisan serve` dan `php artisan queue:listen --tries=1` di dua terminal.
- Semua notifikasi, baik database maupun email, lewat queue `database`. Tanpa worker, job menunggu di tabel `jobs`: notifikasi belum masuk tabel `notifications` dan email belum ditulis. Untuk memproses antrean sekali lalu berhenti: `php artisan queue:work --stop-when-empty`. `queue:work` yang dibiarkan jalan harus di-restart setelah kode berubah; `queue:listen` tidak.
- Dengan `MAIL_MAILER=log`, email ditulis ke `storage/logs/laravel.log`, termasuk tautan reset password.
- Jadwal scheduler ada di `routes/console.php` (`php artisan schedule:list`): `tagihan:generate` tanggal 1 pukul 00:10, `tagihan:tandai-terlambat` 00:30, `kode-tautan:bersihkan` 01:00, `tagihan:pengingat` 07:00 (WIB). Di lokal jalankan `php artisan schedule:work` di terminal terpisah; di server produksi memakai cron (lihat "Instalasi dan menjalankan"). Semua command bisa dicoba tanpa mengubah data dengan `--dry-run`.
- Notifikasi tagihan dan pembayaran juga lewat queue: tanpa worker, notifikasi hasil generate masih di tabel `jobs`.
- Mencoba satu jadwal tanpa menunggu jamnya: `php artisan schedule:test --name=tagihan:generate` (atau nama command lain). Command yang dijalankan scheduler memakai jam sistem sebenarnya.

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
- `TRUSTED_PROXIES` **wajib berisi IP server FE (Next.js)**, ditambah IP reverse proxy di depan BE kalau ada (dipisah koma). FE memakai pola BFF, jadi semua request tanpa login datang dari IP server FE. Laravel hanya membaca IP asli klien dari `X-Forwarded-For` kalau request datang dari proxy yang terdaftar; tanpa itu, semua pengunjung dihitung sebagai satu IP dan berbagi satu kuota limiter `api` (120/menit), `login` (5/menit per email), dan `login-google` (10/menit). Server FE harus meneruskan IP klien di `X-Forwarded-For`.
- Scheduler: cron `* * * * * cd /path/ke/app && php artisan schedule:run >> /dev/null 2>&1`.
- `php.ini`: `upload_max_filesize` minimal `5M` (batas per file di B5) dan `post_max_size` cukup untuk unggahan terbanyak dalam satu request (kegiatan: 10 foto, jadi minimal `55M`). Request yang melewati `post_max_size` dibalas 422 `VALIDATION_ERROR` dengan pesan "Ukuran file terlalu besar. Maksimal 5 MB per file.".

Composer yang dijalankan sebagai root (misalnya di container) menonaktifkan plugin; set `COMPOSER_ALLOW_SUPERUSER=1` supaya plugin Pest terpasang.

## Pengecekan di akhir setiap fase

```bash
php artisan test
./vendor/bin/pint --test
composer phpstan                 # phpstan analyse --memory-limit=1G
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
| `TRUSTED_PROXIES` | IP/CIDR proxy dipisah koma, atau `*`; kosong di lokal. Di server wajib berisi IP server FE (BFF) dan reverse proxy di depan BE. Menentukan apakah header `X-Forwarded-*` dipercaya: IP klien untuk semua rate limiter berbasis IP, serta skema dan host signed URL |
| `SUPERADMIN_NAME`, `SUPERADMIN_EMAIL`, `SUPERADMIN_PASSWORD` | Akun Kepala Sekolah untuk `SuperAdminSeeder` (lewat `config/superadmin.php`). Password minimal 8 karakter berisi huruf dan angka; seeder berhenti dengan pesan jelas kalau kosong atau tidak valid |

`APP_URL` harus sama dengan alamat yang dibuka klien untuk URL file publik (`avatar_url`, `foto_url` guru), karena URL disk `public` dibentuk dari `APP_URL`. Signed URL file private dibentuk dari host request, jadi di balik reverse proxy isi `TRUSTED_PROXIES` supaya header `X-Forwarded-*` dipercaya.

## Dokumentasi API

- UI: `GET /docs/api` (Stoplight Elements), JSON: `GET /docs/api.json`. Hanya terbuka di environment selain `production` (Gate `viewApiDocs`).
- Spec hasil export dikomit di `storage/api-docs/api.json` untuk generate tipe TypeScript di FE. Server di spec: `{APP_URL}/api/v1`, path relatif terhadap prefix itu (misal `/health`).
- Route dengan middleware `auth:sanctum` otomatis bertanda Bearer; route lain `security: []`.
- Respons error ditulis inline per operasi dengan skema A7 dan `code` berupa enum. `ApiErrorResponseExtension` memetakan exception yang terdeteksi Scramble (termasuk `@throws` di service) ke kode A7. `ResponsErrorRouteExtension` menambahkan respons dari middleware yang tidak terdeteksi otomatis: 403 `FORBIDDEN` (`role:`, `signed`), 403 `ACCOUNT_PENDING`/`ACCOUNT_REJECTED`/`ACCOUNT_INACTIVE` (`akun.aktif`), 404 `NOT_FOUND` (route berparameter), 429 `TOO_MANY_REQUESTS` (`throttle:`). `ResponsFileExtension` membuang entri `application/json` kosong di respons file. Beberapa kode pada status yang sama digabung dalam satu enum, misal 422 `BUSINESS_RULE` + `VALIDATION_ERROR`.

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
- Policy (`app/Policies`, ditemukan otomatis dari nama model): `KelasPolicy`, `MuridPolicy`, `TagihanPolicy` memeriksa per data dengan scope yang sama seperti daftar (`Kelas::diampuOleh`, `Murid::visibleTo`, `Tagihan::visibleTo`) dan menolak dengan `Response::denyAsNotFound()`. Laravel mengubah penolakan itu menjadi `HttpException` 404 sebelum `ApiExceptionRenderer`, sehingga balasannya 404 `NOT_FOUND` "Data tidak ditemukan.", sama persis dengan id yang memang tidak ada. Setelah `findOrFail`, controller memanggil `Jangkauan::pastikanTerlihat($model)` untuk Policy `view` yang hanya membalas 404, dan `Gate::authorize(...)` untuk aksi yang bisa membalas 403.
- `signed:relative`: hanya di `GET /media/{token}`.
- Rate limiter (`AppServiceProvider`): `login` 5/menit per email + IP, `login-google` 10/menit per IP, `tautkan-anak` 5/menit per user, dan `api` 120/menit (per user kalau sudah login, per IP kalau belum) untuk semua endpoint kecuali `/health` dan `/media/{token}`.

## Auth dan akun

- Token Sanctum dikirim sebagai `Authorization: Bearer`, berlaku 30 hari, nama token = `perangkat` (`web` | `mobile`). Logout mencabut token yang sedang dipakai; ganti password mencabut token lain; reset password dan penonaktifan akun mencabut semua token.
- Login email hanya untuk Kepala Sekolah dan guru. Email tidak terdaftar, password salah, dan akun wali murid mendapat pesan yang sama ("Email atau password salah."). Status akun baru dicek setelah password benar.
- Login Google (`GoogleLoginService`): ID token diverifikasi `GoogleIdTokenVerifier` (tanda tangan, `aud` = `GOOGLE_CLIENT_ID`, masa berlaku) dan email harus terverifikasi. Akun dicari lewat `google_id` lalu email; email milik guru/Kepala Sekolah ditolak `BUSINESS_RULE`. Email baru dibuatkan akun wali murid aktif dengan `profil_lengkap = false` dan `is_new = true`. Kalau `GOOGLE_CLIENT_ID` kosong, verifier melempar `LayananBelumDikonfigurasiException` (503). Di test, verifier diganti mock.
- Lupa password tidak membedakan email terdaftar atau tidak, dan tidak mengirim apa pun ke akun wali murid (tidak punya password). Tautan berlaku 60 menit (`auth.passwords.users.expire`).
- `PUT /auth/profil` dan `PUT /guru/{id}` menerima `multipart/form-data` dengan metode PUT langsung (tanpa `_method`): PHP 8.4 mem-parse body PUT lewat `request_parse_body()` di Symfony HttpFoundation. Sudah dicoba dengan curl ke server lokal.
- Password baru (registrasi, ganti, reset): minimal 8 karakter berisi huruf dan angka (`Password::defaults()`). Nomor HP: diawali `08`, 10–15 digit (`App\Rules\NomorHp`).
- Gate `kelola-keuangan` memakai `User::bisaKelolaKeuangan()`; endpoint khusus petugas keuangan memakai middleware `can:kelola-keuangan` (403 `FORBIDDEN`, terdokumentasi di OpenAPI lewat `ResponsErrorRouteExtension`). Pembatasan per role lewat middleware `role:`.

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

Setelah review Fase 4–5 (commit `a460da8` di BE, `1562d42` di FE, Bagian A kedua file tetap identik):

15. Petugas keuangan bisa mencatat pembayaran transfer (bukti opsional, langsung diterima), selain tunai (A2.5, A3, A6, A7 `POST /tagihan/{id}/pembayaran`, B6.3).
16. Jenis notifikasi baru `tagihan_tertunda` untuk Kepala Sekolah saat generate terjadwal dilewati karena bulan di luar tahun ajaran aktif (A6, daftar jenis A7, B6.2).
17. Mengeluarkan murid dari kelas ditolak kalau murid sudah punya rapor di kelas itu (B6.5).

Setelah review Fase 6–7 (commit `3ecfee0` di BE, `a6a73e7` di FE, Bagian A tetap identik):

18. `POST /rapor` untuk G dan SA, hanya murid di kelas yang diampu sebagai wali kelas atau pendamping di tahun ajaran aktif.
19. `GET /galeri-album/{id}` (SA) untuk album beserta semua foto; `GET /galeri-album` berisi `cover_url` dan `jumlah_foto` tanpa daftar foto.
20. `POST /pendaftaran` ditolak kalau NIK anak sudah punya pendaftaran selain `ditolak` atau sudah dipakai murid; pendaftar yang pernah ditolak boleh daftar ulang (A7, B6.11).

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

Diambil selama Fase 5 (lihat juga "Keputusan menunggu review"):

- `App\Support\NomorUrut` membuat nomor berurutan berawalan (NIS, `INV-`, `PAY-`, nanti `PPDB-`) dari nilai terbesar dengan `lockForUpdate` di dalam transaksi; unique index tetap penjaga terakhir. Generate tagihan mengunci nomor dulu, baru membaca tagihan yang sudah ada, supaya dua generate bersamaan tidak membuat tagihan ganda.
- Layanan dipisah per tanggung jawab: `TagihanService` (generate bulanan, tagihan sekali, pembatalan, `potongan()`), `JatuhTempoTagihanService` (terlambat dan pengingat), `PembayaranService` (unggah, tunai, terima, tolak), `KwitansiService`, `LaporanKeuanganService`, `JenisTagihanService`, `KeringananService`.
- Pembayaran dan pembatalan mengunci baris tagihan (`lockForUpdate`) supaya aturan "maksimal satu `menunggu` dan satu `diterima`" terjaga. File bukti dihapus lagi kalau transaksi gagal.
- Laporan dihitung di PHP dari baris tagihan/pembayaran (bukan `GROUP BY` fungsi tanggal), supaya hasilnya sama di MySQL, MariaDB, dan SQLite. Datanya kecil (puluhan murid).
- Kwitansi dirender dompdf dengan font subsetting (`isFontSubsettingEnabled`; bawaan config laravel-dompdf mematikannya), ukuran sekitar 24 KB, bukan 880 KB.
- `Tagihan::label()` menghasilkan "SPP Oktober 2026" (bulanan) atau nama jenis (sekali) untuk notifikasi, kwitansi, laporan, dan ekspor. Rupiah ditulis lewat `App\Support\Rupiah::format()`.
- Notifikasi tagihan memakai kelas dasar `NotifikasiTagihan` (menyalin label, nama anak, total, jatuh tempo saat dibuat).
- `AlasanRequest` menggantikan `TolakGuruRequest`, dipakai tolak guru, batalkan tagihan, dan tolak pembayaran.
- `TagihanController` pindah ke `Api\V1\Keuangan` bersama controller keuangan lain.
- Respons laporan diberi anotasi `@response` di controller dan respons file diberi atribut `#[Response]` Scramble, karena Scramble tidak bisa menyimpulkan bentuknya dari service.

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

- Sudah dikerjakan: `DemoSeeder` membuat kode tautan lewat `KodeTautanService::buat()` (Fase 4), nomor INV/PAY lewat `NomorUrut` dengan awalan dari `TagihanService`/`PembayaranService`, dan potongan lewat `TagihanService::potongan()` (Fase 5). Status dan tanggal data demo (lunas, terlambat, menunggu) tetap disusun seeder karena menggambarkan riwayat tiga bulan.
- Sudah dikerjakan di Fase 7: `PengaturanService` dilengkapi penyimpanan, validasi per kunci, dan cache.
- Sudah dikerjakan di Fase 8: respons file, 403 di endpoint detail, dan tipe `*_url` nullable di OpenAPI.
- Belum ada rencana lanjutan dari BE. Yang belum dibuat karena di luar desain saat ini: payment gateway, push notification (FCM), unggah lampiran pengumuman, dan `caption` foto kegiatan lewat API.

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

### Perbaikan OpenAPI (branch `be/fix-openapi`)

Laporan FE: tipe di `api.json` tidak sesuai respons. Respons JSON tidak berubah; yang diubah hanya informasi tipe untuk Scramble.

- `app/Support/ApiResponse.php`: `meta` paginasi (`current_page`, `per_page`, `total`, `last_page`) di-cast `int`, karena Scramble tidak membaca anotasi `@var` paginator dan menulis keempatnya sebagai string di 18 endpoint berpaginasi.
- `app/Http/Controllers/Api/V1/Publik/PublikController.php`: `@response` untuk `GET /public/profil` berisi 16 kunci pengaturan grup `profil` dan `landing` beserta pasangan `*_url`. Field opsional item `landing.*` (misalnya `deskripsi`, `gambar`) ditandai opsional karena tidak ada kalau tidak dikirim saat `PUT /pengaturan`.
- `app/Http/Resources/{AnakWaliResource, MuridResource, TagihanResource}.php`: anotasi bentuk `kelas` (`id` integer). Ditemukan juga lewat test baru: `MuridResource.wali[]` (`hubungan` enum, `is_kontak_utama` boolean, `tertaut_at` date-time) dan `TagihanResource.rekening` (sebelumnya tertulis `null`).
- `tests/Pest.php`: helper `selisihDenganSkema()` dan `skemaSukses()` untuk mencocokkan respons JSON dengan skema OpenAPI (tipe, field wajib, field yang tidak terdokumentasi).
- `tests/Feature/DokumentasiApiTest.php`: meta angka di semua endpoint berpaginasi, bentuk dan respons `GET /public/profil`, `kelas.id` integer, dan respons `GET /wali/anak` serta `GET /murid/{id}` cocok dengan skema.
- `tests/Feature/Hardening/KesesuaianDokumentasiTest.php` (baru): dengan data demo, respons setiap endpoint GET untuk lima jenis pengguna (publik, Kepala Sekolah, guru, bendahara, wali) dicocokkan dengan dokumentasi; lebih dari 100 kombinasi diperiksa dan semuanya cocok.
- `storage/api-docs/api.json`: diekspor ulang.

Hasil pengecekan: 559 test lulus di SQLite dan MariaDB 12.3.3; Pint, PHPStan, dan `check:slop` tanpa temuan.

### Fase 8

File baru:

- `app/Support/Jangkauan.php`: pemeriksaan jangkauan data untuk Policy `view` yang hanya membalas 404.
- `app/Support/Scramble/ResponsFileExtension.php`: membuang `application/json` kosong di respons file.
- `config/trustedproxy.php`: `TRUSTED_PROXIES`.
- Test: `tests/Feature/Hardening/{AuditQueryTest, RateLimitTest, TrustedProxyTest}.php`.

File yang diubah:

- `app/Providers/AppServiceProvider.php`: limiter `api` 120/menit.
- `routes/api.php`: `throttle:api` di grup publik, auth tanpa login, dan grup login; grup `/public` dipindah ke akhir file.
- Controller `Keuangan/TagihanController`, `Kegiatan/KegiatanKelasController`, `Murid/MuridController`, `Ppdb/PendaftaranController`, `Kelas/KelasController`, `Rapor/RaporController`, `Pengumuman/PengumumanController`: `Jangkauan::pastikanTerlihat()`. `Keuangan/PembayaranController`: `@throws AuthorizationException` untuk detail, bukti, dan kwitansi. `MediaController`: atribut respons file.
- `app/Http/Resources/{AkunResource, AnakWaliResource, GaleriAlbumResource, GuruPublikResource, GuruResource, KelasResource, MuridResource, RaporResource, UserResource}.php`: anotasi `@var string|null` untuk `*_url`.
- `app/Http/Requests/Pengaturan/SimpanPengaturanRequest.php`: tipe `items` untuk OpenAPI.
- `config/scramble.php`, `.env.example` (`TRUSTED_PROXIES`), `tests/Feature/DokumentasiApiTest.php` (respons file, 403, tipe bertingkat, url nullable), `README.md`, `storage/api-docs/api.json`, `dokumentasi.md`.

Tambahan setelah review Fase 8: `tests/Feature/Hardening/RateLimitProxyTest.php` (4 test) memastikan limiter `api`, `login`, dan `login-google` memakai IP klien dari `X-Forwarded-For` untuk request dari server FE yang ada di `TRUSTED_PROXIES`, dan mengabaikan header itu dari sumber lain. `config/trustedproxy.php` dan bagian deploy di dokumen ini mencatat bahwa `TRUSTED_PROXIES` wajib berisi IP server FE. Tidak ada perubahan kode aplikasi. Total 554 test.

Hasil pengecekan: 550 test lulus di SQLite dan di MariaDB 12.3.3; Pint, PHPStan (`composer phpstan`), dan `check:slop` tanpa temuan. `api.json` diekspor ulang dan diperiksa dengan skrip: tidak ada field tanpa tipe, tidak ada array bertingkat bertipe `string`, respons file hanya bertipe file.

Verifikasi di MariaDB dengan data demo:

- Queue worker sungguhan: `tagihan:generate --periode=2026-10` (60 tagihan) dan `POST /pengumuman` target semua lewat `php artisan serve` menghasilkan 57 job. `php artisan queue:work --stop-when-empty` memproses semuanya tanpa `failed_jobs`; tabel `notifications` berisi 56 `tagihan_baru` dan 53 `pengumuman_baru` (6 guru + 47 wali; job pengumuman mengirim per potongan di dalam satu job).
- Scheduler: laptop tidak punya `faketime`, jadi `schedule:run` dijalankan lewat skrip kecil (tidak di-commit) yang memalsukan jam scheduler dengan `Carbon::setTestNow`: 1 Oktober 2026 00:10 menjalankan `tagihan:generate`, 00:30 `tagihan:tandai-terlambat`, 01:00 `kode-tautan:bersihkan`, 07:00 `tagihan:pengingat`, dan 09:00 tidak ada jadwal. Keempatnya selesai dengan exit 0. Karena command dijalankan scheduler sebagai proses terpisah dengan jam sistem (27 September), keempat command juga dijalankan langsung: generate September 0 dibuat 60 sudah ada, terlambat 0, pengingat 0, kode tautan 0.
- Data demo diisi ulang setelah verifikasi.

### Revisi setelah review Fase 6–7

- `app/Http/Requests/Rapor/BuatRaporRequest.php`, `app/Services/RaporService.php`: Kepala Sekolah membuat rapor hanya untuk kelas yang dia ampu.
- `app/Http/Controllers/Api/V1/Galeri/GaleriController.php` (`show`), `routes/api.php`: `GET /galeri-album/{id}`; daftar album tanpa foto.
- `app/Services/PendaftaranService.php`: NIK dobel dicek terhadap pendaftaran selain ditolak dan data murid.
- `composer.json`: script `phpstan`.
- `PROMPT_BE_TK.md` (Bagian A, B6.11), `../TK_TA8_FE/PROMPT_FE_TK.md` (Bagian A, di-push ke `main` repo FE).
- Test: `Rapor/AlurRaporTest` (1 baru, 1 disesuaikan), `Galeri/GaleriTest` (1 baru, 1 disesuaikan), `Ppdb/PendaftaranTest` (3 baru menggantikan 1).

### Fase 7

File baru:

- `app/Http/Controllers/Api/V1/Pengaturan/PengaturanController.php`, `Galeri/GaleriController.php`, `Publik/PublikController.php`, `Ppdb/PendaftaranController.php`, `Dashboard/DashboardController.php`, `LogAktivitas/LogAktivitasController.php`.
- `app/Http/Requests/HalamanRequest.php`, `Pengaturan/{DaftarPengaturanRequest, SimpanPengaturanRequest, UnggahGambarPengaturanRequest}.php`, `Galeri/{DaftarGaleriRequest, SimpanAlbumRequest, TambahFotoGaleriRequest, PerbaruiFotoGaleriRequest}.php`, `Pendaftaran/{DaftarPendaftaranRequest, BuatPendaftaranRequest, TerimaPendaftaranRequest}.php`, `Dashboard/DashboardRequest.php`, `LogAktivitas/DaftarLogAktivitasRequest.php`.
- `app/Http/Resources/{GaleriAlbumResource, PengumumanPublikResource, GuruPublikResource, PendaftaranResource, LogAktivitasResource}.php`.
- `app/Policies/PendaftaranPolicy.php`.
- `app/Services/{GaleriService, PendaftaranService, DashboardService}.php`.
- `app/Notifications/{PendaftaranBaruNotification, PendaftaranDiprosesNotification}.php`.
- `app/Support/SlugUnik.php`.
- Test: `tests/Feature/Pengaturan/PengaturanTest.php`, `Galeri/GaleriTest.php`, `Publik/LandingPublikTest.php`, `Ppdb/PendaftaranTest.php`, `Dashboard/DashboardTest.php`, `LogAktivitas/LogAktivitasTest.php`.

File yang diubah:

- `routes/api.php`: 26 operasi Fase 7, grup `/public` tanpa login.
- `app/Services/PengaturanService.php`: ditulis ulang (cache, `untukRespons()`, `simpan()`, `aturan()` per kunci). `app/Models/Pengaturan.php`: event `saved`/`deleted` membuang cache.
- `app/Services/MediaService.php` (`aturanDokumen()`, `simpanDokumen()` untuk gambar/PDF, `salinPrivat()`), `MuridService.php` (`buatDenganFotoTersimpan()`, `FOLDER_FOTO` publik), `PengumumanService.php` (slug lewat `SlugUnik`).
- `app/Models/GaleriAlbum.php` (`fotoPertama()`).
- `tests/Feature/DokumentasiApiTest.php` (endpoint publik tanpa auth, dashboard tiga bentuk).
- `storage/api-docs/api.json`, `dokumentasi.md`.

Hasil pengecekan: 537 test lulus di SQLite dan di MariaDB 12.3.3; Pint, PHPStan, dan `check:slop` tanpa temuan. Data demo diisi ulang lalu dicoba lewat `php artisan serve`: dashboard ketiga role, `/public/*`, daftar PPDB, dan pendaftaran PPDB sungguhan lewat `curl` multipart dengan akta berupa PDF asli (tersimpan `.pdf`, tersaji `application/pdf` lewat signed URL).

### Fase 6

File baru:

- `app/Enums/JenisNotifikasi.php`.
- `app/Http/Controllers/Api/V1/Notifikasi/NotifikasiController.php`, `Agenda/AgendaController.php`, `Kegiatan/KegiatanKelasController.php`, `Rapor/{ElemenPenilaianController, RaporController}.php`, `Pengumuman/PengumumanController.php`.
- `app/Http/Requests/Notifikasi/DaftarNotifikasiRequest.php`, `Agenda/{DaftarAgendaRequest, SimpanAgendaRequest}.php`, `ElemenPenilaian/SimpanElemenPenilaianRequest.php`, `Kegiatan/{DaftarKegiatanRequest, SimpanKegiatanRequest, TambahFotoKegiatanRequest}.php`, `Rapor/{DaftarRaporRequest, BuatRaporRequest, IsiRaporRequest, FotoRaporRequest, CatatanRevisiRequest}.php`, `Pengumuman/{DaftarPengumumanRequest, SimpanPengumumanRequest}.php`.
- `app/Http/Resources/{NotifikasiResource, AgendaResource, ElemenPenilaianResource, KegiatanKelasResource, RaporResource, PengumumanResource}.php`.
- `app/Policies/{KegiatanKelasPolicy, RaporPolicy, PengumumanPolicy}.php`.
- `app/Services/{ElemenPenilaianService, KegiatanKelasService, RaporService, RaporPdfService, PengumumanService}.php`.
- `app/Jobs/KirimNotifikasiPengumuman.php`: mengirim `pengumuman_baru` per 200 penerima di dalam satu job antrean.
- `app/Notifications/{NotifikasiRapor, RaporDiajukanNotification, RaporRevisiNotification, RaporTerbitNotification, PengumumanBaruNotification}.php`.
- `resources/views/pdf/rapor.blade.php`, `resources/views/pdf/bagian/kop.blade.php`.
- Test: `tests/Feature/Notifikasi/NotifikasiTest.php`, `Agenda/AgendaTest.php`, `Kegiatan/KegiatanKelasTest.php`, `Rapor/{ElemenPenilaianTest, AlurRaporTest, AksesRaporTest}.php`, `Pengumuman/PengumumanTest.php`.

File yang diubah:

- `routes/api.php`: 31 operasi Fase 6; pola angka untuk `detail_id`; `{id}` notifikasi memakai `whereUuid`.
- `app/Notifications/*`: `jenis()` mengembalikan `JenisNotifikasi`.
- `app/Models/User.php` (`profilGuru()`), `Agenda.php` (`scopeBerlangsungDi`).
- `app/Services/PengaturanService.php` (`kopSekolah()`, dipindah dari `KwitansiService`), `KwitansiService.php`, `resources/views/pdf/kwitansi.blade.php` (memakai partial kop).
- `tests/Unit/EnumKontrakTest.php` (JenisNotifikasi), `tests/Feature/DokumentasiApiTest.php` (jenis notifikasi enum, rapor PDF).
- `storage/api-docs/api.json`, `dokumentasi.md`.

Hasil pengecekan: 473 test lulus di SQLite dan di MariaDB 12.3.3; Pint, PHPStan (level 6), dan `check:slop` tanpa temuan. Data demo diisi ulang (`migrate:fresh --seed` + `DemoSeeder`) lalu dicoba lewat `php artisan serve`: guru `sri.wahyuni` melihat rapor TK B1 dan mengunduh rapor PDF terbit (satu halaman A4, sekitar 25 KB, dicek visual).

PHPStan: di laptop (PHP CLI `memory_limit` 128M) worker paralel PHPStan kehabisan memori, jadi dijalankan dengan `./vendor/bin/phpstan analyse --memory-limit=1G`.

### Revisi setelah review Fase 4–5

Dikerjakan di branch `be/fase-6-8`. Branch ini dibuat dari `main` (Fase 3), jadi lebih dulu di-fast-forward ke `be/fase-4-5` supaya pekerjaan Fase 4–5 ikut.

- `app/Services/KelasService.php`, `Kelas/PenempatanMuridController.php`: `keluarkanMurid()` menolak murid yang sudah punya rapor di kelas itu.
- `app/Exceptions/PeriodeDiLuarTahunAjaranException.php` (baru, turunan `BusinessRuleException`): dilempar `TagihanService::generateBulanan()` untuk bulan di luar tahun ajaran aktif atau saat belum ada tahun ajaran aktif; membawa periode dan nama tahun ajaran.
- `app/Notifications/TagihanTertundaNotification.php` (baru), `app/Console/Commands/GenerateTagihanCommand.php`: notifikasi `tagihan_tertunda` ke Kepala Sekolah aktif.
- `app/Http/Requests/Pembayaran/BayarTagihanRequest.php`, `app/Services/PembayaranService.php` (`catatTunai()` diganti `catatOlehPetugas()`, penyimpanan bukti bersama di `simpanDenganBukti()`), `Keuangan/PembayaranController.php`, `app/Policies/TagihanPolicy.php`: petugas keuangan mencatat transfer.
- `app/Notifications/PembayaranMasukNotification.php`: url ke `/dashboard/tagihan/{tagihan_id}`.
- `PROMPT_BE_TK.md` (Bagian A dan B6), `../TK_TA8_FE/PROMPT_FE_TK.md` (Bagian A; commit lokal di `main` repo FE, belum di-push). Bagian B FE (tabel route `/dashboard/pembayaran` masih menulis "catat tunai") tidak diubah.
- Test: `Kelas/PenempatanMuridTest` (2), `Keuangan/GenerateTagihanTest` (3), `Keuangan/PembayaranTest` (5 baru, 1 diganti, 1 untuk url). Total 405 test.

### Fase 5

File baru:

- `app/Http/Controllers/Api/V1/Keuangan/{JenisTagihanController, KeringananController, PembayaranController, LaporanController}.php`.
- `app/Http/Requests/AlasanRequest.php`, `JenisTagihan/{DaftarJenisTagihanRequest, SimpanJenisTagihanRequest}.php`, `Keringanan/{DaftarKeringananRequest, SimpanKeringananRequest}.php`, `Tagihan/{BuatTagihanSekaliRequest, GenerateTagihanRequest}.php`, `Pembayaran/{BayarTagihanRequest, DaftarPembayaranRequest}.php`, `Laporan/{LaporanKeuanganRequest, LaporanTunggakanRequest}.php`.
- `app/Http/Resources/{JenisTagihanResource, KeringananResource}.php`.
- `app/Services/{TagihanService, JatuhTempoTagihanService, PembayaranService, KwitansiService, LaporanKeuanganService, JenisTagihanService, KeringananService}.php`.
- `app/Policies/PembayaranPolicy.php`.
- `app/Notifications/{NotifikasiTagihan, TagihanBaruNotification, PengingatTagihanNotification, TagihanTerlambatNotification, PembayaranMasukNotification, PembayaranDiterimaNotification, PembayaranDitolakNotification}.php`.
- `app/Console/Commands/{GenerateTagihanCommand, TandaiTagihanTerlambatCommand, PengingatTagihanCommand}.php`.
- `app/Exports/{LaporanKeuanganExport, TagihanSheet, PembayaranSheet}.php`.
- `app/Support/{NomorUrut, Rupiah}.php`.
- `resources/views/pdf/kwitansi.blade.php`.
- Test: `tests/Feature/Keuangan/{GenerateTagihanTest, TagihanSekaliDanPembatalanTest, PembayaranTest, JatuhTempoTagihanTest, JenisTagihanDanKeringananTest, LaporanKeuanganTest}.php`.

File yang diubah:

- `routes/api.php`: 20 operasi Fase 5; grup `can:kelola-keuangan`.
- `routes/console.php`: jadwal tiga command tagihan.
- `app/Http/Controllers/Api/V1/Tagihan/TagihanController.php` → `Keuangan/TagihanController.php`: ditambah `store`, `generate`, `batalkan`.
- `app/Http/Controllers/Api/V1/Guru/GuruController.php`: memakai `AlasanRequest`; `app/Http/Requests/Guru/TolakGuruRequest.php` dihapus.
- `app/Policies/TagihanPolicy.php`: `bayar()`.
- `app/Models/Tagihan.php` (`label()`), `User.php` (`scopePetugasKeuanganAktif`).
- `app/Services/MediaService.php` (`responsPrivat()`), `MuridService.php` (NIS lewat `NomorUrut`).
- `app/Support/Scramble/ResponsErrorRouteExtension.php`: middleware `can:` didokumentasikan sebagai 403 `FORBIDDEN`.
- `database/seeders/Demo/KeuanganDemoSeeder.php`: nomor INV/PAY dan potongan lewat service.
- `lang/id/validation.php`: nama atribut field Fase 5.
- `phpunit.xml`: `memory_limit=512M`.
- `storage/api-docs/api.json`, `dokumentasi.md`.

Hasil pengecekan: 395 test lulus di SQLite dan di MariaDB 12.3.3; Pint, PHPStan, dan `check:slop` tanpa temuan. Dicoba juga lewat `php artisan serve` dengan data demo: wali mengunggah bukti (JPEG asli), bendahara `siti.rahmawati` menerima, kwitansi PDF terunduh (satu halaman A5, sekitar 24 KB, dicek visual), bukti tersaji `image/jpeg`, laporan dan tunggakan sesuai data demo, ekspor `.xlsx` berisi dua sheet. `schedule:list` menampilkan empat jadwal, dan ketiga command tagihan jalan dengan `--dry-run` di data demo.

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
