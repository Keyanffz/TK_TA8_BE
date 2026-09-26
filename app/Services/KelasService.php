<?php

namespace App\Services;

use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Exceptions\BusinessRuleException;
use App\Models\Kelas;
use App\Models\KelasMurid;
use App\Models\Murid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kelas dan penempatan murid (B6.5): satu murid paling banyak satu kelas per tahun ajaran, dan
 * jumlah murid aktif tidak boleh melebihi kapasitas kelas.
 */
class KelasService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data): Kelas
    {
        return Kelas::query()->create(['kapasitas' => Kelas::KAPASITAS_BAWAAN, ...$data]);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function perbarui(Kelas $kelas, array $data): Kelas
    {
        return DB::transaction(function () use ($kelas, $data): Kelas {
            $kelas = Kelas::query()->lockForUpdate()->findOrFail($kelas->id);
            $pindahTahunAjaran = (int) $data['tahun_ajaran_id'] !== $kelas->tahun_ajaran_id;

            if ($pindahTahunAjaran && $kelas->kelasMurid()->exists()) {
                throw new BusinessRuleException("Kelas {$kelas->nama} sudah berisi murid sehingga tahun ajarannya tidak bisa diganti.");
            }

            $jumlahMurid = $kelas->muridAktif()->count();
            if (isset($data['kapasitas']) && (int) $data['kapasitas'] < $jumlahMurid) {
                throw new BusinessRuleException("Kapasitas tidak bisa kurang dari jumlah murid di kelas ini ({$jumlahMurid} murid).");
            }

            $kelas->update($data);

            return $kelas;
        });
    }

    /**
     * @throws BusinessRuleException
     */
    public function hapus(Kelas $kelas): void
    {
        if ($kelas->kelasMurid()->exists() || $kelas->kegiatan()->exists() || $kelas->rapor()->exists()) {
            throw new BusinessRuleException("Kelas {$kelas->nama} sudah punya murid, kegiatan, atau rapor sehingga tidak bisa dihapus.");
        }

        $kelas->delete();
    }

    /**
     * Baris kelas dan murid dikunci supaya dua penempatan bersamaan tidak melewati kapasitas atau
     * menempatkan murid yang sama di dua kelas.
     *
     * @param  list<int>  $muridIds
     *
     * @throws BusinessRuleException
     */
    public function tempatkanMurid(Kelas $kelas, array $muridIds): Kelas
    {
        DB::transaction(function () use ($kelas, $muridIds): void {
            $kelas = Kelas::query()->lockForUpdate()->findOrFail($kelas->id);
            $murid = Murid::query()->whereKey($muridIds)->lockForUpdate()->orderBy('nama_lengkap')->get();

            $this->pastikanMuridAktif($murid);
            $this->pastikanBelumPunyaKelas($murid, $kelas->tahun_ajaran_id);
            $this->pastikanMuatKapasitas($kelas, $murid->count());

            $kelas->murid()->attach($murid->modelKeys(), ['status' => StatusKelasMurid::Aktif]);
        });

        return $kelas;
    }

    /**
     * Penempatan dihapus, bukan diberi status `keluar`, karena dipakai untuk memperbaiki salah penempatan.
     * Murid yang benar-benar keluar sekolah diubah statusnya lewat data murid.
     */
    public function keluarkanMurid(Kelas $kelas, int $muridId): void
    {
        $penempatan = $kelas->kelasMurid()->where('murid_id', $muridId)->firstOrFail();
        $penempatan->delete();
    }

    /**
     * @param  Collection<int, Murid>  $murid
     *
     * @throws BusinessRuleException
     */
    public function pastikanMuridAktif(Collection $murid): void
    {
        $tidakAktif = $murid->filter(fn (Murid $satu): bool => $satu->status !== StatusMurid::Aktif);

        if ($tidakAktif->isNotEmpty()) {
            throw new BusinessRuleException('Hanya murid berstatus aktif yang bisa ditempatkan di kelas: '.$tidakAktif->pluck('nama_lengkap')->join(', ').'.');
        }
    }

    /**
     * @param  Collection<int, Murid>  $murid
     *
     * @throws BusinessRuleException
     */
    public function pastikanBelumPunyaKelas(Collection $murid, int $tahunAjaranId): void
    {
        $sudahPunyaKelas = KelasMurid::query()
            ->with(['murid', 'kelas'])
            ->whereIn('murid_id', $murid->modelKeys())
            ->whereHas('kelas', fn (Builder $kelas) => $kelas->where('tahun_ajaran_id', $tahunAjaranId))
            ->get();

        if ($sudahPunyaKelas->isNotEmpty()) {
            $daftar = $sudahPunyaKelas->map(fn (KelasMurid $p): string => "{$p->murid->nama_lengkap} ({$p->kelas->nama})")->join(', ');

            throw new BusinessRuleException("Murid berikut sudah punya kelas di tahun ajaran ini: {$daftar}.");
        }
    }

    /**
     * @throws BusinessRuleException
     */
    public function pastikanMuatKapasitas(Kelas $kelas, int $jumlahBaru): void
    {
        $sisa = $kelas->kapasitas - $kelas->muridAktif()->count();

        if ($jumlahBaru > $sisa) {
            throw new BusinessRuleException("Kelas {$kelas->nama} hanya punya sisa {$sisa} tempat (kapasitas {$kelas->kapasitas}), sedangkan yang akan ditempatkan {$jumlahBaru} murid.");
        }
    }
}
