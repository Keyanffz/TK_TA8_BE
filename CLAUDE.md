# CLAUDE.md

Aturan kerja untuk repo backend ini, berlaku di setiap sesi.

1. Sebelum mengerjakan apa pun, baca `PROMPT_BE_TK.md` sampai habis dan `dokumentasi.md` (setelah dibuat di Fase 1). `PROMPT_BE_TK.md` adalah acuan tunggal desain dan kontrak API; `dokumentasi.md` mencatat status fase, changelog, dan keputusan teknis terakhir.
2. Kerjakan satu fase (Bagian D) saja. Di akhir fase jalankan pengecekan yang diwajibkan, perbarui `dokumentasi.md`, laporkan hasilnya, lalu berhenti dan tunggu konfirmasi sebelum lanjut ke fase berikutnya.
3. Patuhi Bagian C (anti AI-slop) di semua kode, test, teks, dokumentasi, dan pesan commit.
4. Laporan akhir fase (dan balasan ke pemilik repo) ditulis dalam Bahasa Indonesia.

## Hemat pengujian

- php artisan test (SQLite), Pint, PHPStan, dan check:slop tetap dijalankan penuh. Pakai output ringkas (misalnya --compact) dan tampilkan hanya ringkasan dan error.
- Test ke MariaDB hanya kalau ada perubahan migration, query, atau seeder.
- Uji manual lewat curl atau server hanya untuk endpoint yang baru atau berubah.
- Jangan membaca ulang file besar (api.json, composer.lock) kecuali perlu.
