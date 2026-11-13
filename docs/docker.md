# Deploy dengan Docker

Revisi sekolah nomor 2. Backend, frontend, queue, scheduler, dan MariaDB berjalan sebagai container. Nginx host dan certbot tetap di host untuk TLS dan meneruskan request ke port loopback container.

## Bentuk

| Service | Image | Isi | Port |
|---|---|---|---|
| `app` | `tkta8-be` (`Dockerfile` repo ini) | FrankenPHP melayani HTTP dan PHP dalam satu proses | `127.0.0.1:8000` → 8080 |
| `queue` | `tkta8-be` | `php artisan queue:work` | - |
| `scheduler` | `tkta8-be` | `php artisan schedule:work` (pengganti cron `schedule:run`) | - |
| `db` | `mariadb:11.8` (sama dengan VPS) | volume `tkta8_db` | tidak dibuka |
| `fe` | `tkta8-fe` (`Dockerfile` repo FE) | `node server.js` dari `output: "standalone"` | `127.0.0.1:3000`, jaringan host |

- FrankenPHP dipilih, bukan PHP-FPM ditambah Nginx kedua di dalam container: satu proses per container dan tidak ada konfigurasi Nginx yang harus dijaga dua kali. Nginx host tetap memegang TLS.
- `storage/` ketiga service BE adalah satu volume `tkta8_storage`, jadi foto yang diunggah lewat `app` terbaca oleh `queue`.
- `fe` memakai jaringan host. FE memanggil BE lewat `BE_API_URL`, yaitu alamat yang juga dibuka browser (signed URL file private dibentuk dari host request), jadi alamat itu harus bisa dijangkau dari dalam container sama seperti dari host. Server tetap hanya mendengar `127.0.0.1:3000`.
- Migration tidak dijalankan otomatis saat container mulai. `entrypoint.sh` hanya menjalankan `php artisan optimize`, karena nilai `.env` baru tersedia saat container berjalan.
- Satu file `deploy/.env` dipakai Laravel (lewat `env_file`) dan compose (variabel MariaDB, `BE_API_URL`, folder repo FE). `.env` tidak masuk image.

## File

- `Dockerfile`, `docker/php.ini` (batas sama dengan pool PHP-FPM `tkta8`: unggahan 105 MB, memori 256 MB, 120 detik), `docker/entrypoint.sh`, `.dockerignore`.
- `deploy/compose.yaml`, `deploy/.env.example`.
- Repo FE: `Dockerfile`, `.dockerignore`.

Perintah di bawah dijalankan dari folder `deploy/`.

## Mencoba di laptop

Kedua repo bersebelahan (`TK_TA8_BE` dan `TK_TA8_FE`), port 3000 dan 8000 sedang kosong.

```bash
cp .env.example .env
php ../artisan key:generate --show   # salin hasilnya ke APP_KEY
```

Ubah di `.env`: `APP_URL=http://localhost:8000`, `FRONTEND_URL=http://localhost:3000`, `BE_API_URL=http://localhost:8000/api/v1`, `FE_DIR=../../TK_TA8_FE`, `TRUSTED_PROXIES=127.0.0.1,::1,172.16.0.0/12`, `MAIL_MAILER=log`, isi `DB_PASSWORD` dan akun `SUPERADMIN_*`.

```bash
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
docker compose restart queue scheduler
```

`queue` dan `scheduler` berhenti dan dinyalakan ulang terus sampai tabel ada; restart setelah migration mempercepatnya. Cookie sesi production memakai `Secure`; browser tetap menerimanya di `http://localhost`, tetapi tidak di alamat IP lain tanpa HTTPS.

## Pindah dari instalasi sekarang

Uji seluruh langkah ini dulu di VM `debian-lab`, baru di VPS. Instalasi lama (kode `/var/www/tk/be` dan `/var/www/tk/fe`, MariaDB host, PHP-FPM, service systemd) tidak dihapus sampai Docker berjalan stabil, supaya rollback tinggal menyalakan lagi.

1. Pasang Docker Engine dan plugin compose dari repo resmi Docker untuk Debian. Masukkan user deploy ke grup `docker` (anggota grup ini setara root di mesin itu).

2. Simpan konfigurasi lama:

   ```bash
   sudo cp /etc/nginx/sites-available/tkta8-be ~/tkta8-be.nginx.sebelum-docker
   crontab -l > ~/crontab.sebelum-docker
   ```

3. Tarik kode kedua repo (`git pull` di `/var/www/tk/be` dan `/var/www/tk/fe`). Buat `deploy/.env` dari `.env.example`:
   - `APP_KEY`, `MAIL_*`, `GOOGLE_CLIENT_ID`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` disalin dari `/var/www/tk/be/.env`. `APP_KEY` harus sama; kalau berganti, data terenkripsi lama tidak terbaca.
   - `TRUSTED_PROXIES` = nilai lama ditambah `172.16.0.0/12`: request dari Nginx host masuk ke container lewat gateway jaringan Docker.
   - `BE_API_URL` dari `/var/www/tk/fe/.env.production`; `GOOGLE_CLIENT_ID` yang sama dipakai FE.
   - `FE_DIR=../../fe`.

4. Build image sebelum aplikasi lama dimatikan, supaya waktu henti hanya selama pindah data:

   ```bash
   docker compose build
   ```

5. Hentikan penulisan di instalasi lama:

   ```bash
   cd /var/www/tk/be && php artisan down
   sudo systemctl stop tkta8-queue
   crontab -e   # beri tanda # pada baris schedule:run
   ```

6. Dump database host lalu impor ke container:

   ```bash
   mariadb-dump --single-transaction -u tkta8 -p tkta8 > ~/pindah-docker.sql
   cd /var/www/tk/be/deploy
   docker compose up -d db
   docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < ~/pindah-docker.sql
   ```

   Database dan user di container dibuat dari `DB_*` saat volume `tkta8_db` pertama kali dibuat. Mengubah `DB_PASSWORD` setelah itu tidak mengubah password user di database.

7. Salin `storage/app` (foto, lampiran, file private) ke volume:

   ```bash
   docker compose up -d app
   docker compose cp /var/www/tk/be/storage/app/. app:/app/storage/app/
   docker compose exec -u root app chown -R www-data:www-data /app/storage/app
   ```

8. Matikan FE dan queue lama, lalu nyalakan semua service:

   ```bash
   sudo systemctl disable --now tkta8-fe tkta8-queue
   docker compose up -d
   docker compose exec app php artisan migrate --force   # kalau kode baru membawa migration
   ```

9. Ganti site Nginx BE dari PHP-FPM ke proxy. Di `sites-available/tkta8-be`, hapus `root`, `index`, dan blok `location` PHP, lalu isi blok server HTTPS dengan:

   ```nginx
   client_max_body_size 105M;

   location / {
       proxy_pass http://127.0.0.1:8000;
       proxy_set_header Host $host;
       proxy_set_header X-Forwarded-For $remote_addr;
       proxy_set_header X-Forwarded-Proto $scheme;
       proxy_read_timeout 120s;
   }
   ```

   Bagian sertifikat dari certbot dan redirect HTTP ke HTTPS tidak diubah. Site FE tidak berubah karena FE tetap di `127.0.0.1:3000`.

   ```bash
   sudo nginx -t && sudo systemctl reload nginx
   ```

10. Ganti crontab: baris `schedule:run` dihapus (sudah ada service `scheduler`), backup DB lewat container:

    ```cron
    0 3 * * * cd /var/www/tk/be/deploy && docker compose exec -T db sh -c 'mariadb-dump --single-transaction -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' | gzip > ~/backups/tkta8/tkta8-$(date +\%F).sql.gz && find ~/backups/tkta8 -name '*.sql.gz' -mtime +14 -delete
    ```

11. Periksa: `curl https://api.tarbiyathulathfal8.cloud/api/v1/health`, login Kepala Sekolah (password dan Google), buka foto lama dan file private (foto absensi), unggah foto baru, kirim pengumuman lalu lihat `docker compose logs queue`. Kalau semua baik, `php8.5-fpm` dan MariaDB host boleh dihentikan (`sudo systemctl disable --now php8.5-fpm mariadb`), tetapi jangan dihapus dulu.

## Deploy berikutnya

```bash
cd /var/www/tk/be && git pull && cd /var/www/tk/fe && git pull
cd /var/www/tk/be/deploy
docker compose up -d --build
docker compose exec app php artisan migrate --force
```

Container dibuat ulang, jadi `queue:restart` tidak perlu. `BE_API_URL` dan `GOOGLE_CLIENT_ID` ditanam ke image FE saat build; setelah mengubahnya jalankan `docker compose up -d --build fe`.

Backup `storage/app` ke luar volume:

```bash
docker run --rm -v tkta8_storage:/s -v ~/backups/tkta8:/b debian:bookworm-slim tar czf /b/storage-$(date +%F).tar.gz -C /s app
```

`docker compose down -v` menghapus volume database dan storage. Jangan dipakai di server.

Log: `docker compose logs -f app queue scheduler fe`. Log Laravel tetap di `storage/logs` dalam volume: `docker compose exec app ls storage/logs`.

## Rollback

Kalau Docker bermasalah dan belum ada data baru yang penting:

```bash
cd /var/www/tk/be/deploy && docker compose down       # volume tetap ada
sudo cp ~/tkta8-be.nginx.sebelum-docker /etc/nginx/sites-available/tkta8-be
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl enable --now mariadb php8.5-fpm tkta8-queue tkta8-fe
crontab ~/crontab.sebelum-docker
cd /var/www/tk/be && php artisan up
```

Kalau sudah ada data yang ditulis selama di Docker (pembayaran, absensi, unggahan), pindahkan dulu sebelum `docker compose down`:

```bash
cd /var/www/tk/be/deploy
docker compose exec -T db sh -c 'mariadb-dump --single-transaction -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' > ~/kembali-dari-docker.sql
docker compose cp app:/app/storage/app/. /var/www/tk/be/storage/app/
sudo chown -R keyens:keyens /var/www/tk/be/storage/app
sudo systemctl start mariadb
mariadb -u tkta8 -p tkta8 < ~/kembali-dari-docker.sql
```

Lalu jalankan blok rollback di atas. Kode lama di `/var/www/tk/be` harus berada di commit yang skema databasenya sama dengan dump; kalau Docker menjalankan migration baru, `php artisan migrate --force` juga di instalasi lama.

## Yang sudah dan belum diuji

Diuji di laptop (Arch, Docker 29.9, Compose 5.6, tanpa buildx): build kedua image, `up`, migration dan seeder, health dan halaman publik, login Kepala Sekolah lewat BFF FE, unggah gambar lewat `/api/proxy` sampai tersimpan di volume dan tersaji di `/storage`, optimizer gambar FE, notifikasi lewat `queue`, `scheduler` menjalankan `absensi:tandai-tidak-hadir`, data bertahan setelah `down` dan `up`, dump dan impor lewat container, salin storage dengan `docker compose cp`.

Belum diuji: Nginx host dengan TLS di depan container, `TRUSTED_PROXIES` dengan request sungguhan dari Nginx, signed URL file private di domain, login Google, impor dump production, dan seluruh langkah pindah di VM `debian-lab`.
