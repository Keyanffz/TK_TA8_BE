<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Enums\StatusKelasMurid;
use App\Enums\TargetPengumuman;
use App\Jobs\KirimNotifikasiPengumuman;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pengumuman;
use App\Models\User;
use App\Support\SlugUnik;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Stevebauman\Purify\Facades\Purify;
use Throwable;

/**
 * Pengumuman (B6.10). Isi HTML disanitasi sebelum disimpan. Notifikasi `pengumuman_baru` dikirim sekali, saat
 * pengumuman pertama kali terbit (bukan saat draft disimpan atau pengumuman terbit diubah). File lampiran disimpan
 * sebelum transaksi dan dihapus lagi kalau penyimpanan gagal; file lama baru dihapus setelah perubahan tersimpan.
 */
class PengumumanService
{
    private const FOLDER_LAMPIRAN = 'pengumuman';

    // Kolom lampiran_nama berukuran 255 karakter; sisanya untuk ekstensi.
    private const PANJANG_MAKSIMAL_NAMA = 200;

    private const TANPA_LAMPIRAN = ['lampiran_path' => null, 'lampiran_nama' => null, 'lampiran_ukuran' => null];

    public function __construct(private readonly MediaService $media) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $kelasIds
     * @param  list<int>  $muridIds
     */
    public function buat(array $data, array $kelasIds, array $muridIds, bool $terbitkan, ?UploadedFile $lampiran, User $penulis): Pengumuman
    {
        $kolomLampiran = $lampiran === null ? [] : $this->simpanLampiran($lampiran);

        try {
            $pengumuman = DB::transaction(function () use ($data, $kolomLampiran, $kelasIds, $muridIds, $terbitkan, $penulis): Pengumuman {
                $pengumuman = Pengumuman::query()->create([
                    ...$data,
                    ...$kolomLampiran,
                    'isi' => Purify::clean((string) $data['isi']),
                    'slug' => SlugUnik::dari(Pengumuman::withTrashed(), (string) $data['judul'], 'pengumuman'),
                    'penulis_id' => $penulis->id,
                    'published_at' => $terbitkan ? now() : null,
                ]);
                $pengumuman->kelas()->sync($kelasIds);
                $pengumuman->murid()->sync($muridIds);

                return $pengumuman;
            });
        } catch (Throwable $e) {
            $this->media->hapus($kolomLampiran['lampiran_path'] ?? null, MediaService::DISK_PRIVAT);

            throw $e;
        }

        if ($terbitkan) {
            KirimNotifikasiPengumuman::dispatch($pengumuman->id);
        }

        return $pengumuman;
    }

    /**
     * Slug tidak berubah walau judul diganti, supaya tautan pengumuman publik yang sudah dibagikan tetap jalan.
     * `publish: false` pada pengumuman yang sudah terbit menariknya kembali menjadi draft. Lampiran baru
     * menggantikan yang lama; `$hapusLampiran` mengosongkannya.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $kelasIds
     * @param  list<int>  $muridIds
     */
    public function perbarui(
        Pengumuman $pengumuman,
        array $data,
        array $kelasIds,
        array $muridIds,
        bool $terbitkan,
        ?UploadedFile $lampiran,
        bool $hapusLampiran,
    ): Pengumuman {
        $baruTerbit = $terbitkan && $pengumuman->published_at === null;
        $lampiranLama = $pengumuman->lampiran_path;
        $kolomLampiran = match (true) {
            $lampiran !== null => $this->simpanLampiran($lampiran),
            $hapusLampiran => self::TANPA_LAMPIRAN,
            default => [],
        };

        try {
            DB::transaction(function () use ($pengumuman, $data, $kolomLampiran, $kelasIds, $muridIds, $terbitkan): void {
                $pengumuman->update([
                    ...$data,
                    ...$kolomLampiran,
                    'isi' => Purify::clean((string) $data['isi']),
                    'published_at' => $terbitkan ? ($pengumuman->published_at ?? now()) : null,
                ]);
                $pengumuman->kelas()->sync($kelasIds);
                $pengumuman->murid()->sync($muridIds);
            });
        } catch (Throwable $e) {
            $this->media->hapus($kolomLampiran['lampiran_path'] ?? null, MediaService::DISK_PRIVAT);

            throw $e;
        }

        if ($kolomLampiran !== []) {
            $this->media->hapus($lampiranLama, MediaService::DISK_PRIVAT);
        }

        if ($baruTerbit) {
            KirimNotifikasiPengumuman::dispatch($pengumuman->id);
        }

        return $pengumuman;
    }

    /**
     * Pengumuman dihapus lunak, tetapi file lampirannya langsung dihapus (B5) karena tidak ada jalan untuk
     * memulihkan pengumuman yang terhapus.
     */
    public function hapus(Pengumuman $pengumuman): void
    {
        $pengumuman->delete();
        $this->media->hapus($pengumuman->lampiran_path, MediaService::DISK_PRIVAT);
    }

    /**
     * Gambar dikecilkan seperti foto lain (B5) dan selalu menjadi JPEG, PDF disimpan apa adanya. Ekstensi nama
     * tampilan mengikuti file yang tersimpan supaya cocok dengan isinya saat diunduh. Garis miring dan karakter
     * kontrol dibuang karena nama ini dipakai di header `Content-Disposition`.
     *
     * @return array{lampiran_path: string, lampiran_nama: string, lampiran_ukuran: int}
     */
    private function simpanLampiran(UploadedFile $file): array
    {
        $path = $this->media->simpanDokumen($file, MediaService::DISK_PRIVAT, self::FOLDER_LAMPIRAN);
        $namaAsli = (string) preg_replace('#[\x00-\x1F\x7F/\\\\]#u', '', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $nama = Str::limit(trim($namaAsli), self::PANJANG_MAKSIMAL_NAMA, '');

        return [
            'lampiran_path' => $path,
            'lampiran_nama' => ($nama === '' ? 'lampiran' : $nama).'.'.pathinfo($path, PATHINFO_EXTENSION),
            'lampiran_ukuran' => Storage::disk(MediaService::DISK_PRIVAT)->size($path),
        ];
    }

    /**
     * Pengguna aktif yang menjadi sasaran pengumuman, selain penulisnya. Untuk target kelas dan murid: wali
     * murid yang anaknya disasar ditambah guru yang mengampu kelas itu, sama dengan yang melihatnya di feed. Target
     * semua dan wali murid hanya sampai ke wali yang masih punya anak aktif.
     *
     * @return Builder<User>
     */
    public function penerima(Pengumuman $pengumuman): Builder
    {
        $query = User::query()->where('status', StatusAkun::Aktif)->whereKeyNot($pengumuman->penulis_id);

        return match ($pengumuman->target) {
            TargetPengumuman::Semua => $query->where(fn (Builder $user) => $user
                ->where('role', Role::Guru)
                ->orWhere(fn (Builder $wali) => $wali->waliDenganAnakAktif())),
            TargetPengumuman::Guru => $query->where('role', Role::Guru),
            TargetPengumuman::WaliMurid => $query->waliDenganAnakAktif(),
            TargetPengumuman::Kelas => $this->waliDanGuruKelas(
                $query,
                fn (Builder $murid) => $murid->whereHas('kelasMurid', fn (Builder $penempatan) => $penempatan
                    ->where('status', StatusKelasMurid::Aktif)
                    ->whereIn('kelas_id', $pengumuman->kelas->modelKeys())),
                $pengumuman->kelas->modelKeys(),
            ),
            TargetPengumuman::Murid => $this->waliDanGuruKelas(
                $query,
                fn (Builder $murid) => $murid->whereKey($pengumuman->murid->modelKeys()),
                Kelas::query()->whereHas('tahunAjaran', fn (Builder $tahunAjaran) => $tahunAjaran->aktif())
                    ->whereHas('kelasMurid', fn (Builder $penempatan) => $penempatan->whereIn('murid_id', $pengumuman->murid->modelKeys()))
                    ->pluck('id')->all(),
            ),
        };
    }

    /**
     * @param  Builder<User>  $query
     * @param  callable(Builder<Murid>): mixed  $muridSasaran
     * @param  list<int>  $kelasIds
     * @return Builder<User>
     */
    private function waliDanGuruKelas(Builder $query, callable $muridSasaran, array $kelasIds): Builder
    {
        return $query->where(fn (Builder $user) => $user
            ->whereHas('waliMurid.murid', $muridSasaran)
            ->orWhereHas('guru', fn (Builder $guru) => $guru
                ->whereIn('id', Kelas::query()->whereKey($kelasIds)->select('wali_kelas_id'))
                ->orWhereIn('id', Kelas::query()->whereKey($kelasIds)->select('guru_pendamping_id'))));
    }
}
