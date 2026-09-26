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
use Illuminate\Support\Facades\DB;
use Stevebauman\Purify\Facades\Purify;

/**
 * Pengumuman (B6.10). Isi HTML disanitasi sebelum disimpan. Notifikasi `pengumuman_baru` dikirim sekali, saat
 * pengumuman pertama kali terbit (bukan saat draft disimpan atau pengumuman terbit diubah).
 */
class PengumumanService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $kelasIds
     * @param  list<int>  $muridIds
     */
    public function buat(array $data, array $kelasIds, array $muridIds, bool $terbitkan, User $penulis): Pengumuman
    {
        $pengumuman = DB::transaction(function () use ($data, $kelasIds, $muridIds, $terbitkan, $penulis): Pengumuman {
            $pengumuman = Pengumuman::query()->create([
                ...$data,
                'isi' => Purify::clean((string) $data['isi']),
                'slug' => SlugUnik::dari(Pengumuman::withTrashed(), (string) $data['judul'], 'pengumuman'),
                'penulis_id' => $penulis->id,
                'published_at' => $terbitkan ? now() : null,
            ]);
            $pengumuman->kelas()->sync($kelasIds);
            $pengumuman->murid()->sync($muridIds);

            return $pengumuman;
        });

        if ($terbitkan) {
            KirimNotifikasiPengumuman::dispatch($pengumuman->id);
        }

        return $pengumuman;
    }

    /**
     * Slug tidak berubah walau judul diganti, supaya tautan pengumuman publik yang sudah dibagikan tetap jalan.
     * `publish: false` pada pengumuman yang sudah terbit menariknya kembali menjadi draft.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $kelasIds
     * @param  list<int>  $muridIds
     */
    public function perbarui(Pengumuman $pengumuman, array $data, array $kelasIds, array $muridIds, bool $terbitkan): Pengumuman
    {
        $baruTerbit = $terbitkan && $pengumuman->published_at === null;

        DB::transaction(function () use ($pengumuman, $data, $kelasIds, $muridIds, $terbitkan): void {
            $pengumuman->update([
                ...$data,
                'isi' => Purify::clean((string) $data['isi']),
                'published_at' => $terbitkan ? ($pengumuman->published_at ?? now()) : null,
            ]);
            $pengumuman->kelas()->sync($kelasIds);
            $pengumuman->murid()->sync($muridIds);
        });

        if ($baruTerbit) {
            KirimNotifikasiPengumuman::dispatch($pengumuman->id);
        }

        return $pengumuman;
    }

    /**
     * Pengguna aktif yang menjadi sasaran pengumuman, selain penulisnya. Untuk target kelas dan murid: wali
     * murid yang anaknya disasar ditambah guru yang mengampu kelas itu, sama dengan yang melihatnya di feed.
     *
     * @return Builder<User>
     */
    public function penerima(Pengumuman $pengumuman): Builder
    {
        $query = User::query()->where('status', StatusAkun::Aktif)->whereKeyNot($pengumuman->penulis_id);

        return match ($pengumuman->target) {
            TargetPengumuman::Semua => $query->whereIn('role', [Role::Guru, Role::WaliMurid]),
            TargetPengumuman::Guru => $query->where('role', Role::Guru),
            TargetPengumuman::WaliMurid => $query->where('role', Role::WaliMurid),
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
