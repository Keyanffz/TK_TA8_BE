<?php

namespace App\Services;

use App\Enums\JenisAbsensi;
use App\Enums\Role;
use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Models\AksesAbsenSusulan;
use App\Models\User;
use App\Support\AturanAbsensi;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DokumenPdf;
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
     * Absen masuk dan pulang setiap peserta pada satu tanggal, beserta akses absen susulannya untuk tanggal itu,
     * dengan aturan peserta yang sama seperti rekap.
     *
     * @return list<array{user: array{id: int, nama: string, jabatan: string|null}, masuk: Absensi|null, pulang: Absensi|null, akses_susulan: EloquentCollection<int, AksesAbsenSusulan>}>
     */
    public function harian(Carbon $tanggal): array
    {
        $padaTanggal = fn (Builder $absensi) => $absensi->whereDate('tanggal', $tanggal->toDateString());
        $absensi = Absensi::query()->with('pengoreksi')->tap($padaTanggal)->get()->groupBy('user_id');
        $akses = AksesAbsenSusulan::query()->with('pemberi')->whereDate('tanggal', $tanggal->toDateString())->orderBy('id')->get()->groupBy('user_id');

        return $this->peserta($padaTanggal)->map(function (User $user) use ($absensi, $akses): array {
            /** @var Collection<int, Absensi> $milik */
            $milik = $absensi->get($user->id, collect());

            return [
                'user' => $this->ringkasan($user),
                'masuk' => $milik->firstWhere('jenis', JenisAbsensi::Masuk),
                'pulang' => $milik->firstWhere('jenis', JenisAbsensi::Pulang),
                'akses_susulan' => $akses->get($user->id, new EloquentCollection),
            ];
        })->values()->all();
    }

    /**
     * Rekap dalam PDF A4 landscape berkop sekolah. Kolom nama dan jabatan diberi lebar, jadi nama panjang dan
     * bergelar terbungkus di selnya sendiri, tidak mendorong kolom angka.
     */
    public function pdf(Carbon $awal, Carbon $akhir): DokumenPdf
    {
        return Pdf::loadView('pdf.rekap-absensi', $this->isiPdf($awal, $akhir))
            ->setPaper('a4', 'landscape')
            ->setOption('isFontSubsettingEnabled', true);
    }

    /**
     * @return array{sekolah: array<string, string|null>, rentang: string, rekap: list<array<string, mixed>>}
     */
    public function isiPdf(Carbon $awal, Carbon $akhir): array
    {
        return [
            'sekolah' => $this->pengaturan->kopSekolah(),
            'rentang' => self::labelRentang($awal, $akhir),
            'rekap' => $this->rekap($awal, $akhir),
        ];
    }

    /** "Oktober 2026", "Agustus – Oktober 2026", atau "Desember 2025 – Januari 2026". */
    public static function labelRentang(Carbon $awal, Carbon $akhir): string
    {
        if ($awal->isSameMonth($akhir)) {
            return $akhir->translatedFormat('F Y');
        }

        return ($awal->isSameYear($akhir) ? $awal->translatedFormat('F') : $awal->translatedFormat('F Y')).' – '.$akhir->translatedFormat('F Y');
    }

    /** `rekap-absensi-2026-10` atau `rekap-absensi-2026-08-sampai-2026-10`, tanpa ekstensi. */
    public static function namaFile(Carbon $awal, Carbon $akhir): string
    {
        return 'rekap-absensi-'.($awal->isSameMonth($akhir) ? $akhir->format('Y-m') : "{$awal->format('Y-m')}-sampai-{$akhir->format('Y-m')}");
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
