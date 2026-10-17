<?php

namespace App\Services;

use App\Enums\JenisAbsensi;
use App\Enums\Role;
use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Models\User;
use App\Support\AturanAbsensi;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rekap absensi per peserta untuk 1–3 bulan dan absensi semua peserta pada satu tanggal, untuk Kepala Sekolah.
 * Rekap dihitung di PHP dari baris absensi rentang itu (belasan peserta, paling banyak 186 baris per orang).
 */
class RekapAbsensiService
{
    public function __construct(private readonly PengaturanService $pengaturan) {}

    /**
     * Rekap tanggal `$awal` sampai `$akhir` (1–3 bulan). Peserta = guru dan Kepala Sekolah yang akunnya aktif,
     * ditambah akun nonaktif yang masih punya absensi di rentang itu. "Tidak absen pulang" dihitung untuk absen
     * masuk hadir atau terlambat pada hari yang jam pulangnya sudah tutup.
     *
     * @return list<array{user: array{id: int, nama: string, jabatan: string|null}, hadir: int, terlambat: int, izin: int, sakit: int, tidak_hadir: int, tidak_absen_pulang: int}>
     */
    public function rekap(Carbon $awal, Carbon $akhir): array
    {
        $dalamRentang = fn (Builder $absensi) => $absensi->whereDate('tanggal', '>=', $awal->toDateString())->whereDate('tanggal', '<=', $akhir->toDateString());

        $absensi = Absensi::query()->tap($dalamRentang)->get()->groupBy('user_id');

        return $this->peserta($dalamRentang)->map(function (User $user) use ($absensi): array {
            /** @var Collection<int, Absensi> $milik */
            $milik = $absensi->get($user->id, collect());
            $masuk = $milik->where('jenis', JenisAbsensi::Masuk);

            return [
                'user' => $this->ringkasan($user),
                'hadir' => $masuk->where('status', StatusAbsensi::Hadir)->count(),
                'terlambat' => $masuk->where('status', StatusAbsensi::Terlambat)->count(),
                'izin' => $masuk->where('status', StatusAbsensi::Izin)->count(),
                'sakit' => $masuk->where('status', StatusAbsensi::Sakit)->count(),
                'tidak_hadir' => $masuk->where('status', StatusAbsensi::TidakHadir)->count(),
                'tidak_absen_pulang' => $this->tidakAbsenPulang($milik),
            ];
        })->values()->all();
    }

    /**
     * Absen masuk dan pulang setiap peserta pada satu tanggal, dengan aturan peserta yang sama seperti rekap.
     *
     * @return list<array{user: array{id: int, nama: string, jabatan: string|null}, masuk: Absensi|null, pulang: Absensi|null}>
     */
    public function harian(Carbon $tanggal): array
    {
        $padaTanggal = fn (Builder $absensi) => $absensi->whereDate('tanggal', $tanggal->toDateString());
        $absensi = Absensi::query()->with('pengoreksi')->tap($padaTanggal)->get()->groupBy('user_id');

        return $this->peserta($padaTanggal)->map(function (User $user) use ($absensi): array {
            /** @var Collection<int, Absensi> $milik */
            $milik = $absensi->get($user->id, collect());

            return [
                'user' => $this->ringkasan($user),
                'masuk' => $milik->firstWhere('jenis', JenisAbsensi::Masuk),
                'pulang' => $milik->firstWhere('jenis', JenisAbsensi::Pulang),
            ];
        })->values()->all();
    }

    /**
     * Isi file CSV rekap, diawali BOM UTF-8 supaya nama bergelar terbaca benar di Excel.
     */
    public function csv(Carbon $awal, Carbon $akhir): string
    {
        $berkas = fopen('php://temp', 'r+');
        fwrite($berkas, "\xEF\xBB\xBF");
        fputcsv($berkas, ['Nama', 'Jabatan', 'Hadir', 'Terlambat', 'Izin', 'Sakit', 'Tidak Hadir', 'Tidak Absen Pulang'], escape: '');

        foreach ($this->rekap($awal, $akhir) as $baris) {
            fputcsv($berkas, [
                $baris['user']['nama'], $baris['user']['jabatan'] ?? '',
                $baris['hadir'], $baris['terlambat'], $baris['izin'], $baris['sakit'], $baris['tidak_hadir'], $baris['tidak_absen_pulang'],
            ], escape: '');
        }

        rewind($berkas);
        $isi = (string) stream_get_contents($berkas);
        fclose($berkas);

        return $isi;
    }

    /**
     * Guru dan Kepala Sekolah yang aktif, ditambah akun nonaktif yang punya absensi di rentang `$punyaAbsensi`,
     * urut nama.
     *
     * @param  Closure(Builder<Absensi>): mixed  $punyaAbsensi
     * @return EloquentCollection<int, User>
     */
    private function peserta(Closure $punyaAbsensi): EloquentCollection
    {
        return User::query()->with('guru')
            ->whereIn('role', [Role::SuperAdmin, Role::Guru])
            ->where(fn (Builder $user) => $user->pesertaAbsensi()->orWhereHas('absensi', $punyaAbsensi))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{id: int, nama: string, jabatan: string|null}
     */
    private function ringkasan(User $user): array
    {
        return ['id' => $user->id, 'nama' => $user->name, 'jabatan' => $user->guru?->jabatan];
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
            ->filter(fn (Absensi $masuk): bool => $masuk->status?->perluAbsenPulang() === true)
            ->reject(fn (Absensi $masuk): bool => $tanggalPulang->contains($masuk->tanggal->toDateString()))
            ->filter(fn (Absensi $masuk): bool => $masuk->tanggal->isBefore($sekarang->copy()->startOfDay())
                || ($masuk->tanggal->isSameDay($sekarang) && $pulangHariIniTutup))
            ->count();
    }
}
