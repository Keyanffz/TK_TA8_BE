<?php

namespace App\Services;

use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Exceptions\BusinessRuleException;
use App\Models\Kelas;
use App\Models\KelasMurid;
use App\Models\Murid;
use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kenaikan kelas massal di akhir tahun ajaran (B6.5), dalam satu transaksi: penempatan di tahun ajaran
 * aktif ditutup dengan status naik/tinggal/lulus, lalu murid yang naik atau tinggal ditempatkan di kelas
 * tahun ajaran tujuan. Murid lulus berstatus `lulus` dengan tanggal keluar = akhir tahun ajaran aktif.
 */
class KenaikanKelasService
{
    public function __construct(private readonly KelasService $kelasService) {}

    /**
     * @param  list<array{murid_id: int, status: StatusKelasMurid, kelas_tujuan_id: int|null}>  $penempatan
     * @return array{naik: int, tinggal: int, lulus: int}
     *
     * @throws BusinessRuleException
     */
    public function proses(TahunAjaran $tujuan, array $penempatan): array
    {
        $asal = TahunAjaran::query()->aktif()->first()
            ?? throw new BusinessRuleException('Belum ada tahun ajaran aktif. Aktifkan tahun ajaran yang sedang berjalan terlebih dahulu.');

        if ($tujuan->id === $asal->id) {
            throw new BusinessRuleException('Tahun ajaran tujuan harus berbeda dengan tahun ajaran aktif.');
        }

        return DB::transaction(function () use ($asal, $tujuan, $penempatan): array {
            $murid = Murid::query()->whereKey(array_column($penempatan, 'murid_id'))->lockForUpdate()->get();
            $this->kelasService->pastikanMuridAktif($murid);
            $this->kelasService->pastikanBelumPunyaKelas($murid, $tujuan->id);

            $penempatanLama = $this->penempatanDiTahunAjaran($murid, $asal);
            $this->pastikanKelasTujuanValid($penempatan, $tujuan);

            foreach ($penempatan as $baris) {
                $this->terapkan($baris, $penempatanLama[$baris['murid_id']], $murid->find($baris['murid_id']), $asal);
            }

            return [
                'naik' => count(array_filter($penempatan, fn (array $b): bool => $b['status'] === StatusKelasMurid::Naik)),
                'tinggal' => count(array_filter($penempatan, fn (array $b): bool => $b['status'] === StatusKelasMurid::Tinggal)),
                'lulus' => count(array_filter($penempatan, fn (array $b): bool => $b['status'] === StatusKelasMurid::Lulus)),
            ];
        });
    }

    /**
     * @param  Collection<int, Murid>  $murid
     * @return Collection<int, KelasMurid> penempatan aktif per murid_id
     *
     * @throws BusinessRuleException
     */
    private function penempatanDiTahunAjaran(Collection $murid, TahunAjaran $asal): Collection
    {
        $penempatan = KelasMurid::query()
            ->whereIn('murid_id', $murid->modelKeys())
            ->where('status', StatusKelasMurid::Aktif)
            ->whereHas('kelas', fn (Builder $kelas) => $kelas->where('tahun_ajaran_id', $asal->id))
            ->lockForUpdate()
            ->get()
            ->keyBy('murid_id');

        $tanpaKelas = $murid->reject(fn (Murid $satu): bool => $penempatan->has($satu->id));

        if ($tanpaKelas->isNotEmpty()) {
            throw new BusinessRuleException("Murid berikut tidak punya kelas aktif di tahun ajaran {$asal->nama}: ".$tanpaKelas->pluck('nama_lengkap')->join(', ').'.');
        }

        return $penempatan;
    }

    /**
     * @param  list<array{murid_id: int, status: StatusKelasMurid, kelas_tujuan_id: int|null}>  $penempatan
     *
     * @throws BusinessRuleException
     */
    private function pastikanKelasTujuanValid(array $penempatan, TahunAjaran $tujuan): void
    {
        $jumlahPerKelas = array_count_values(array_filter(array_column($penempatan, 'kelas_tujuan_id')));
        $kelasTujuan = Kelas::query()->whereKey(array_keys($jumlahPerKelas))->lockForUpdate()->get();

        $salahTahun = $kelasTujuan->where('tahun_ajaran_id', '!==', $tujuan->id);
        if ($salahTahun->isNotEmpty()) {
            throw new BusinessRuleException("Kelas tujuan harus di tahun ajaran {$tujuan->nama}: ".$salahTahun->pluck('nama')->join(', ').' bukan kelas tahun ajaran itu.');
        }

        foreach ($kelasTujuan as $kelas) {
            $this->kelasService->pastikanMuatKapasitas($kelas, $jumlahPerKelas[$kelas->id]);
        }
    }

    /**
     * @param  array{murid_id: int, status: StatusKelasMurid, kelas_tujuan_id: int|null}  $baris
     */
    private function terapkan(array $baris, KelasMurid $penempatanLama, ?Murid $murid, TahunAjaran $asal): void
    {
        $penempatanLama->update(['status' => $baris['status']]);

        if ($baris['kelas_tujuan_id'] !== null) {
            KelasMurid::query()->create([
                'kelas_id' => $baris['kelas_tujuan_id'],
                'murid_id' => $baris['murid_id'],
                'status' => StatusKelasMurid::Aktif,
            ]);

            return;
        }

        $murid?->update(['status' => StatusMurid::Lulus, 'tanggal_keluar' => $asal->tanggal_selesai]);
    }
}
