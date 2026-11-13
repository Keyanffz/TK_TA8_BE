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
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
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
    /** Isi sel jam untuk absensi yang dicatat lewat koreksi, yang tidak punya jam server. */
    public const TANPA_JAM = 'Tanpa jam';

    /** Isi sel jam dan status kalau absensinya tidak ada. */
    public const KOSONG = '-';

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
        $dalamRentang = $this->dalamRentang($awal, $akhir);

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
     * Absensi harian setiap peserta di rentang itu untuk ekspor, dengan isi sel yang sudah berupa teks. Hari yang
     * ditulis hanya hari absensi: hari kerja yang bukan tanggal libur, tidak sebelum tanggal mulai, dan tidak setelah
     * hari ini (hari kerja dan libur menurut pengaturan sekarang). Peserta sama dengan rekap, urut nama.
     *
     * @return list<array{user: array{id: int, nama: string, jabatan: string|null}, hari: list<array{tanggal: CarbonInterface, jam_masuk: string, jam_pulang: string, status: string, keterangan: string}>}>
     */
    public function harianPerPeserta(Carbon $awal, Carbon $akhir): array
    {
        $tanggal = $this->hariAbsensi($awal, $akhir);
        if ($tanggal === []) {
            return [];
        }

        $dalamRentang = $this->dalamRentang($awal, $akhir);
        $absensi = Absensi::query()->tap($dalamRentang)->get()
            ->groupBy(fn (Absensi $satu): string => "{$satu->user_id} {$satu->tanggal->toDateString()}");

        return $this->peserta($dalamRentang)->map(fn (User $user): array => [
            'user' => $this->ringkasan($user),
            'hari' => array_map(
                fn (CarbonInterface $hari): array => $this->barisHarian($hari, $absensi->get("{$user->id} {$hari->toDateString()}", collect())),
                $tanggal,
            ),
        ])->values()->all();
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
     * @return array{sekolah: array<string, string|null>, rentang: string, rekap: list<array<string, mixed>>, harian: list<array<string, mixed>>}
     */
    public function isiPdf(Carbon $awal, Carbon $akhir): array
    {
        return [
            'sekolah' => $this->pengaturan->kopSekolah(),
            'rentang' => self::labelRentang($awal, $akhir),
            'rekap' => $this->rekap($awal, $akhir),
            'harian' => $this->harianPerPeserta($awal, $akhir),
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
     * @return Closure(Builder<Absensi>): Builder<Absensi>
     */
    private function dalamRentang(Carbon $awal, Carbon $akhir): Closure
    {
        return fn (Builder $absensi): Builder => $absensi->whereDate('tanggal', '>=', $awal->toDateString())->whereDate('tanggal', '<=', $akhir->toDateString());
    }

    /**
     * @return list<CarbonInterface>
     */
    private function hariAbsensi(Carbon $awal, Carbon $akhir): array
    {
        $aturan = AturanAbsensi::dari($this->pengaturan);
        $sampai = $akhir->isAfter(today()) ? today() : $akhir;

        return array_values(array_filter(
            CarbonPeriod::create($awal->copy()->startOfDay(), $sampai)->toArray(),
            fn (CarbonInterface $hari): bool => $aturan->sudahMulai($hari) && $aturan->hariKerja($hari) && ! $aturan->tanggalLibur($hari),
        ));
    }

    /**
     * @param  Collection<int, Absensi>  $absensi  absensi satu peserta di tanggal itu
     * @return array{tanggal: CarbonInterface, jam_masuk: string, jam_pulang: string, status: string, keterangan: string}
     */
    private function barisHarian(CarbonInterface $tanggal, Collection $absensi): array
    {
        $masuk = $absensi->firstWhere('jenis', JenisAbsensi::Masuk);
        $pulang = $absensi->firstWhere('jenis', JenisAbsensi::Pulang);

        return [
            'tanggal' => $tanggal,
            'jam_masuk' => $this->selJam($masuk),
            'jam_pulang' => $this->selJam($pulang),
            'status' => $masuk?->status?->label() ?? self::KOSONG,
            'keterangan' => $this->keterangan($masuk, $pulang),
        ];
    }

    /**
     * Jam server saat absen. Absensi yang dicatat lewat koreksi tidak punya jam; tanda tidak hadir dari scheduler juga
     * tidak, tetapi itu berarti tidak ada absensinya.
     */
    private function selJam(?Absensi $absensi): string
    {
        return match (true) {
            $absensi === null || $absensi->status === StatusAbsensi::TidakHadir => self::KOSONG,
            $absensi->waktu !== null => $absensi->waktu->format('H:i'),
            default => self::TANPA_JAM,
        };
    }

    /**
     * Alasan dari peserta (izin, sakit, pulang lebih awal), tanda susulan, dan catatan koreksi, dipisah titik koma.
     */
    private function keterangan(?Absensi $masuk, ?Absensi $pulang): string
    {
        $alasanPulang = $pulang?->keterangan === null ? null : ($pulang->pulang_awal ? 'Pulang lebih awal: ' : 'Keterangan pulang: ').$pulang->keterangan;

        return collect([
            $masuk?->keterangan,
            $masuk?->susulan ? 'Absen masuk susulan' : null,
            $masuk?->catatan_koreksi === null ? null : "Catatan koreksi: {$masuk->catatan_koreksi}",
            $alasanPulang,
            $pulang?->susulan ? 'Absen pulang susulan' : null,
            $pulang?->catatan_koreksi === null ? null : "Catatan absen pulang: {$pulang->catatan_koreksi}",
        ])->filter()->implode('; ');
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
