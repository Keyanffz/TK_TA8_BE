<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\IzinKurangException;
use App\Models\AbsensiMurid;
use App\Models\Kelas;
use App\Models\LiburKelas;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hari sekolah mingguan dan tanggal libur absensi murid per kelas (A2.15). Dilihat Kepala Sekolah, wali kelas, dan
 * guru pendamping kelas itu; diubah Kepala Sekolah dan wali kelas. Absensi yang sudah tercatat tidak dihapus saat
 * aturannya berubah, hanya tidak dihitung di rekap (lihat `AbsensiMuridService::rekap()`).
 */
class HariSekolahKelasService
{
    /**
     * @return array{hari_sekolah: list<int>, libur: list<array{id: int, tanggal_mulai: string, tanggal_selesai: string, keterangan: string}>, bisa_diatur: bool}
     */
    public function data(Kelas $kelas, User $user): array
    {
        return [
            'hari_sekolah' => $kelas->hari_sekolah,
            'libur' => $kelas->libur()->orderBy('tanggal_mulai')->get()->map(fn (LiburKelas $libur): array => [
                'id' => $libur->id,
                'tanggal_mulai' => $libur->tanggal_mulai->toDateString(),
                'tanggal_selesai' => $libur->tanggal_selesai->toDateString(),
                'keterangan' => $libur->keterangan,
            ])->values()->all(),
            'bisa_diatur' => $this->bisaDiatur($kelas, $user),
        ];
    }

    /**
     * `$kelas` sudah disaring `Kelas::untukPengampu()`, jadi guru di sini selalu pengampu kelas di tahun ajaran aktif.
     *
     * @throws IzinKurangException
     */
    public function pastikanBolehAtur(Kelas $kelas, User $user): void
    {
        if (! $this->bisaDiatur($kelas, $user)) {
            throw IzinKurangException::khususWaliKelas();
        }
    }

    /**
     * @param  list<int>  $hari
     */
    public function ubahHari(Kelas $kelas, array $hari): void
    {
        $kelas->update(['hari_sekolah' => $hari]);
    }

    /**
     * Kelas dikunci selama memeriksa libur yang bertumpuk, supaya dua penambahan bersamaan tidak lolos keduanya.
     *
     * @throws BusinessRuleException
     */
    public function tambahLibur(Kelas $kelas, Carbon $mulai, Carbon $selesai, string $keterangan): LiburKelas
    {
        $tahunAjaran = $kelas->tahunAjaran;
        if ($mulai->isBefore($tahunAjaran->tanggal_mulai) || $selesai->isAfter($tahunAjaran->tanggal_selesai)) {
            throw new BusinessRuleException("Tanggal libur harus di dalam tahun ajaran {$tahunAjaran->nama} ({$this->rentang($tahunAjaran->tanggal_mulai, $tahunAjaran->tanggal_selesai)}).");
        }

        return DB::transaction(function () use ($kelas, $mulai, $selesai, $keterangan): LiburKelas {
            Kelas::query()->lockForUpdate()->findOrFail($kelas->id);

            $bertumpuk = $kelas->libur()
                ->whereDate('tanggal_mulai', '<=', $selesai->toDateString())
                ->whereDate('tanggal_selesai', '>=', $mulai->toDateString())
                ->first();
            if ($bertumpuk !== null) {
                throw new BusinessRuleException("Tanggal itu bertumpuk dengan libur \"{$bertumpuk->keterangan}\" ({$this->rentang($bertumpuk->tanggal_mulai, $bertumpuk->tanggal_selesai)}). Hapus libur itu dulu kalau ingin mengubahnya.");
            }

            return $kelas->libur()->create([
                'tanggal_mulai' => $mulai->toDateString(),
                'tanggal_selesai' => $selesai->toDateString(),
                'keterangan' => $keterangan,
            ]);
        });
    }

    public function hapusLibur(Kelas $kelas, int $liburId): LiburKelas
    {
        $libur = $kelas->libur()->findOrFail($liburId);
        $libur->delete();

        return $libur;
    }

    public function adaAbsensiTercatat(LiburKelas $libur): bool
    {
        return AbsensiMurid::query()->where('kelas_id', $libur->kelas_id)
            ->whereDate('tanggal', '>=', $libur->tanggal_mulai->toDateString())
            ->whereDate('tanggal', '<=', $libur->tanggal_selesai->toDateString())
            ->exists();
    }

    /** "22 Desember 2026" atau "22 Desember 2026 sampai 2 Januari 2027". */
    public function rentang(CarbonInterface $mulai, CarbonInterface $selesai): string
    {
        return $mulai->isSameDay($selesai)
            ? $mulai->translatedFormat('j F Y')
            : $mulai->translatedFormat('j F Y').' sampai '.$selesai->translatedFormat('j F Y');
    }

    private function bisaDiatur(Kelas $kelas, User $user): bool
    {
        return $user->role === Role::SuperAdmin || ($kelas->wali_kelas_id !== null && $kelas->wali_kelas_id === $user->guru?->id);
    }
}
