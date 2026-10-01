<?php

namespace App\Services;

use App\Enums\JenisAbsensi;
use App\Enums\Role;
use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Models\User;
use App\Support\AturanAbsensi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rekap absensi per bulan per peserta untuk Kepala Sekolah, dihitung di PHP dari baris absensi bulan itu
 * (belasan peserta, paling banyak 62 baris per orang).
 */
class RekapAbsensiService
{
    public function __construct(private readonly PengaturanService $pengaturan) {}

    /**
     * Peserta = guru dan Kepala Sekolah yang akunnya aktif, ditambah akun nonaktif yang masih punya absensi
     * di bulan itu. "Tidak absen pulang" dihitung untuk hari yang jam pulangnya sudah tutup.
     *
     * @return list<array{user: array{id: int, nama: string, jabatan: string|null}, hadir: int, terlambat: int, tidak_hadir: int, tidak_absen_pulang: int}>
     */
    public function rekap(Carbon $bulan): array
    {
        $awal = $bulan->copy()->startOfMonth()->toDateString();
        $akhir = $bulan->copy()->endOfMonth()->toDateString();
        $dalamBulan = fn (Builder $absensi) => $absensi->whereDate('tanggal', '>=', $awal)->whereDate('tanggal', '<=', $akhir);

        $absensi = Absensi::query()->tap($dalamBulan)->get()->groupBy('user_id');
        $peserta = User::query()->with('guru')
            ->whereIn('role', [Role::SuperAdmin, Role::Guru])
            ->where(fn (Builder $user) => $user->pesertaAbsensi()->orWhereHas('absensi', $dalamBulan))
            ->orderBy('name')
            ->get();

        return $peserta->map(function (User $user) use ($absensi): array {
            /** @var Collection<int, Absensi> $milik */
            $milik = $absensi->get($user->id, collect());
            $masuk = $milik->where('jenis', JenisAbsensi::Masuk);

            return [
                'user' => ['id' => $user->id, 'nama' => $user->name, 'jabatan' => $user->guru?->jabatan],
                'hadir' => $masuk->where('status', StatusAbsensi::Hadir)->count(),
                'terlambat' => $masuk->where('status', StatusAbsensi::Terlambat)->count(),
                'tidak_hadir' => $masuk->where('status', StatusAbsensi::TidakHadir)->count(),
                'tidak_absen_pulang' => $this->tidakAbsenPulang($milik),
            ];
        })->values()->all();
    }

    /**
     * Isi file CSV rekap, diawali BOM UTF-8 supaya nama bergelar terbaca benar di Excel.
     */
    public function csv(Carbon $bulan): string
    {
        $berkas = fopen('php://temp', 'r+');
        fwrite($berkas, "\xEF\xBB\xBF");
        fputcsv($berkas, ['Nama', 'Jabatan', 'Hadir', 'Terlambat', 'Tidak Hadir', 'Tidak Absen Pulang'], escape: '');

        foreach ($this->rekap($bulan) as $baris) {
            fputcsv($berkas, [
                $baris['user']['nama'], $baris['user']['jabatan'] ?? '',
                $baris['hadir'], $baris['terlambat'], $baris['tidak_hadir'], $baris['tidak_absen_pulang'],
            ], escape: '');
        }

        rewind($berkas);
        $isi = (string) stream_get_contents($berkas);
        fclose($berkas);

        return $isi;
    }

    /**
     * @param  Collection<int, Absensi>  $absensi
     */
    private function tidakAbsenPulang(Collection $absensi): int
    {
        $sekarang = now();
        $pulangHariIniTutup = AturanAbsensi::dari($this->pengaturan)->jendelaSudahTutup(JenisAbsensi::Pulang, $sekarang);
        $tanggalPulang = $absensi->where('jenis', JenisAbsensi::Pulang)->map(fn (Absensi $satu): string => $satu->tanggal->toDateString());

        return $absensi
            ->where('jenis', JenisAbsensi::Masuk)
            ->filter(fn (Absensi $masuk): bool => $masuk->status !== StatusAbsensi::TidakHadir)
            ->reject(fn (Absensi $masuk): bool => $tanggalPulang->contains($masuk->tanggal->toDateString()))
            ->filter(fn (Absensi $masuk): bool => $masuk->tanggal->isBefore($sekarang->copy()->startOfDay())
                || ($masuk->tanggal->isSameDay($sekarang) && $pulangHariIniTutup))
            ->count();
    }
}
