<?php

namespace App\Services;

use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Exceptions\BusinessRuleException;
use App\Models\Kelas;
use App\Models\KelasMurid;
use App\Models\Murid;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Status murid aktif, lulus, pindah, atau keluar (A2.14). Setiap perubahan dicatat di `riwayat_status_murid` dan
 * ikut mengubah penempatan di tahun ajaran aktif: lulus → `lulus`, pindah/keluar → `keluar`, kembali aktif →
 * penempatan dibuka lagi (dengan cek kapasitas). Tagihan lama tidak disentuh; murid non-aktif tidak lagi ikut
 * generate tagihan karena generate hanya untuk murid aktif.
 *
 * @phpstan-type Perubahan array{status: StatusMurid, tanggal: CarbonInterface, tahun_lulus: int|null, sekolah_tujuan: string|null, keterangan: string|null}
 */
class StatusMuridService
{
    /** Batas bawah longgar supaya data alumni dari tahun-tahun lama tetap bisa dimasukkan. */
    public const TAHUN_LULUS_PALING_AWAL = 1950;

    /** Tanggal lulus kalau tahun ajaran yang berakhir di tahun lulus itu tidak tercatat: akhir Juni. */
    private const BULAN_LULUS_BAWAAN = 6;

    private const TANGGAL_LULUS_BAWAAN = 30;

    public function __construct(private readonly KelasService $kelasService) {}

    /**
     * @param  Perubahan  $perubahan
     *
     * @throws BusinessRuleException
     */
    public function ubah(Murid $murid, array $perubahan, User $pelaku): Murid
    {
        if ($murid->status === $perubahan['status']) {
            throw new BusinessRuleException("{$murid->nama_lengkap} sudah berstatus {$perubahan['status']->label()}.");
        }

        DB::transaction(function () use ($murid, $perubahan, $pelaku): void {
            Murid::query()->lockForUpdate()->findOrFail($murid->id);
            $this->terapkan($murid, $perubahan, $pelaku);
        });

        activity('murid')->causedBy($pelaku)->performedOn($murid)->event('status_diubah')
            ->withProperties(['ke' => $perubahan['status']->value, 'tanggal' => $perubahan['tanggal']->toDateString()])
            ->log("Mengubah status {$murid->nama_lengkap} menjadi {$perubahan['status']->label()}");

        return $murid;
    }

    /**
     * Mengubah status banyak murid dalam satu transaksi. Murid yang statusnya sudah sama dilewati. Satu murid yang
     * melanggar aturan (misalnya kelas penuh saat diaktifkan kembali) membatalkan seluruh perubahan.
     *
     * @param  list<int>  $muridIds
     * @param  Perubahan  $perubahan
     * @return array{diubah: int, dilewati: int}
     *
     * @throws BusinessRuleException
     */
    public function ubahMassal(array $muridIds, array $perubahan, User $pelaku): array
    {
        $diubah = DB::transaction(function () use ($muridIds, $perubahan, $pelaku): int {
            $murid = Murid::query()->whereKey($muridIds)->where('status', '!=', $perubahan['status'])->lockForUpdate()->get();

            foreach ($murid as $satu) {
                $this->terapkan($satu, $perubahan, $pelaku);
            }

            return $murid->count();
        });

        $dilewati = count($muridIds) - $diubah;
        activity('murid')->causedBy($pelaku)->event('status_diubah_massal')
            ->withProperties(['ke' => $perubahan['status']->value, 'tanggal' => $perubahan['tanggal']->toDateString(), 'diubah' => $diubah, 'dilewati' => $dilewati])
            ->log("Mengubah status {$diubah} murid menjadi {$perubahan['status']->label()}");

        return ['diubah' => $diubah, 'dilewati' => $dilewati];
    }

    /**
     * Murid dengan penempatan aktif di kelas itu, untuk "luluskan satu kelas".
     *
     * @return list<int>
     *
     * @throws BusinessRuleException
     */
    public function muridAktifDiKelas(Kelas $kelas): array
    {
        $ids = $kelas->muridAktif()->pluck('murid.id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        if ($ids === []) {
            throw new BusinessRuleException("Tidak ada murid aktif di {$kelas->nama}.");
        }

        return $ids;
    }

    /**
     * Akhir tahun ajaran yang selesai di tahun lulus itu, dipakai sebagai tanggal lulus kalau tanggalnya tidak
     * diketahui (data alumni lama di import).
     */
    public function tanggalLulusBawaan(int $tahunLulus): CarbonInterface
    {
        $tahunAjaran = TahunAjaran::query()
            ->whereBetween('tanggal_selesai', ["{$tahunLulus}-01-01", "{$tahunLulus}-12-31"])
            ->orderByDesc('tanggal_selesai')
            ->first();

        return $tahunAjaran?->tanggal_selesai->copy()
            ?? Carbon::create($tahunLulus, self::BULAN_LULUS_BAWAAN, self::TANGGAL_LULUS_BAWAAN)->startOfDay();
    }

    /**
     * Dipanggil di dalam transaksi pemanggil, dengan baris murid yang sudah dikunci. Juga dipakai kenaikan kelas
     * untuk murid yang lulus.
     *
     * @param  Perubahan  $perubahan
     *
     * @throws BusinessRuleException
     */
    public function terapkan(Murid $murid, array $perubahan, User $pelaku): void
    {
        ['status' => $status, 'tanggal' => $tanggal] = $perubahan;
        $aktif = $status === StatusMurid::Aktif;

        if ($tanggal->lt($murid->tanggal_masuk)) {
            throw new BusinessRuleException("Tanggal status {$murid->nama_lengkap} tidak boleh sebelum tanggal masuknya ({$murid->tanggal_masuk->translatedFormat('j F Y')}).");
        }
        if ($perubahan['tahun_lulus'] !== null && $perubahan['tahun_lulus'] < $murid->tanggal_masuk->year) {
            throw new BusinessRuleException("Tahun lulus {$murid->nama_lengkap} tidak boleh sebelum tahun masuknya ({$murid->tanggal_masuk->year}).");
        }

        $statusLama = $murid->status;
        $murid->update([
            'status' => $status,
            'tanggal_keluar' => $aktif ? null : $tanggal->toDateString(),
            'tahun_lulus' => $status === StatusMurid::Lulus ? $perubahan['tahun_lulus'] : null,
            'sekolah_tujuan' => $status === StatusMurid::Pindah ? $perubahan['sekolah_tujuan'] : null,
            'keterangan_status' => $aktif ? null : $perubahan['keterangan'],
        ]);

        $murid->riwayatStatus()->create([
            'status_lama' => $statusLama,
            'status_baru' => $status,
            'tanggal' => $tanggal->toDateString(),
            'tahun_lulus' => $murid->tahun_lulus,
            'sekolah_tujuan' => $murid->sekolah_tujuan,
            'keterangan' => $perubahan['keterangan'],
            'diubah_oleh' => $pelaku->id,
        ]);

        $this->sesuaikanPenempatan($murid, $status);
    }

    /**
     * @throws BusinessRuleException
     */
    private function sesuaikanPenempatan(Murid $murid, StatusMurid $statusBaru): void
    {
        [$dari, $ke] = match ($statusBaru) {
            StatusMurid::Aktif => [[StatusKelasMurid::Lulus, StatusKelasMurid::Keluar], StatusKelasMurid::Aktif],
            StatusMurid::Lulus => [[StatusKelasMurid::Aktif], StatusKelasMurid::Lulus],
            StatusMurid::Pindah, StatusMurid::Keluar => [[StatusKelasMurid::Aktif], StatusKelasMurid::Keluar],
        };

        $penempatan = KelasMurid::query()
            ->with('kelas')
            ->where('murid_id', $murid->id)
            ->whereIn('status', $dari)
            ->whereHas('kelas.tahunAjaran', fn (Builder $tahunAjaran) => $tahunAjaran->where('is_aktif', true))
            ->lockForUpdate()
            ->first();

        if ($penempatan === null) {
            return;
        }

        if ($ke === StatusKelasMurid::Aktif) {
            $this->kelasService->pastikanMuatKapasitas($penempatan->kelas, 1);
        }

        $penempatan->update(['status' => $ke]);
    }
}
