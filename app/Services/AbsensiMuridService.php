<?php

namespace App\Services;

use App\Enums\StatusAbsensiMurid;
use App\Enums\StatusKelasMurid;
use App\Exceptions\BusinessRuleException;
use App\Models\AbsensiMurid;
use App\Models\Kelas;
use App\Models\LiburKelas;
use App\Models\Murid;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DokumenPdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Absensi harian murid per kelas (A2.15), dicatat wali kelas, guru pendamping kelas itu di tahun ajaran aktif, atau
 * Kepala Sekolah. Tanggal yang bisa dicatat mengikuti hari sekolah dan libur kelas itu, bukan pengaturan absensi guru.
 */
class AbsensiMuridService
{
    public function __construct(private readonly PengaturanService $pengaturan) {}

    /**
     * Sebab tanggal itu tidak bisa dicatat, atau null kalau bisa.
     */
    public function alasanTanggalDitolak(Kelas $kelas, Carbon $tanggal): ?string
    {
        $teks = $tanggal->translatedFormat('l, j F Y');
        $tahunAjaran = $kelas->tahunAjaran;
        $libur = $kelas->liburPada($tanggal);

        return match (true) {
            $tanggal->isAfter(today()) => "{$teks} belum tiba. Absensi murid hanya untuk hari ini atau sebelumnya.",
            ! $kelas->hariSekolah($tanggal) => "{$teks} bukan hari sekolah {$kelas->nama}.",
            $libur !== null => "{$teks} termasuk libur {$kelas->nama}: {$libur->keterangan}.",
            $tanggal->isBefore($tahunAjaran->tanggal_mulai) || $tanggal->isAfter($tahunAjaran->tanggal_selesai) => "{$teks} di luar tahun ajaran {$tahunAjaran->nama}.",
            default => null,
        };
    }

    /**
     * Murid berpenempatan aktif di kelas itu, ditambah murid yang sudah punya absensi di kelas itu pada tanggal
     * tersebut (misalnya yang kemudian pindah), urut nama.
     *
     * @return array{tanggal: string, bisa_dicatat: bool, alasan: string|null, murid: list<array{murid: array{id: int, nis: string, nama_lengkap: string}, status: StatusAbsensiMurid|null, keterangan: string|null}>}
     */
    public function harian(Kelas $kelas, Carbon $tanggal): array
    {
        $tercatat = AbsensiMurid::query()->where('kelas_id', $kelas->id)->whereDate('tanggal', $tanggal->toDateString())->get()->keyBy('murid_id');
        $alasan = $this->alasanTanggalDitolak($kelas, $tanggal);

        return [
            'tanggal' => $tanggal->toDateString(),
            'bisa_dicatat' => $alasan === null,
            'alasan' => $alasan,
            'murid' => $this->murid($kelas, $tercatat->keys()->all())->map(fn (Murid $murid): array => [
                'murid' => $this->ringkasan($murid),
                'status' => $tercatat->get($murid->id)?->status,
                'keterangan' => $tercatat->get($murid->id)?->keterangan,
            ])->values()->all(),
        ];
    }

    /**
     * Menyimpan absensi satu kelas untuk satu tanggal dalam satu transaksi. Semua murid aktif kelas itu wajib ada,
     * dan tidak boleh ada murid lain.
     *
     * @param  list<array{murid_id: int, status: StatusAbsensiMurid, keterangan: string|null}>  $absensi
     *
     * @throws BusinessRuleException
     */
    public function simpan(Kelas $kelas, Carbon $tanggal, array $absensi, User $pencatat): void
    {
        $alasan = $this->alasanTanggalDitolak($kelas, $tanggal);
        if ($alasan !== null) {
            throw new BusinessRuleException($alasan);
        }

        $dikirim = array_column($absensi, 'murid_id');
        $aktif = $kelas->muridAktif()->pluck('murid.id')->all();
        $lain = array_diff($dikirim, $aktif);
        $kurang = array_diff($aktif, $dikirim);
        if ($lain !== []) {
            throw new BusinessRuleException('Ada murid yang tidak berpenempatan aktif di kelas ini. Muat ulang daftar murid, lalu simpan lagi.');
        }
        if ($kurang !== []) {
            throw new BusinessRuleException('Absensi harus memuat semua murid aktif di kelas ini ('.count($kurang).' murid belum diisi).');
        }

        try {
            $this->simpanBaris($kelas, $tanggal, $absensi, $dikirim, $pencatat);
        } catch (UniqueConstraintViolationException) {
            // Wali kelas dan guru pendamping menyimpan tanggal yang sama bersamaan.
            throw new BusinessRuleException('Absensi tanggal ini baru saja disimpan guru lain. Muat ulang daftar murid, lalu simpan lagi.');
        }
    }

    /**
     * @param  list<array{murid_id: int, status: StatusAbsensiMurid, keterangan: string|null}>  $absensi
     * @param  list<int>  $dikirim
     */
    private function simpanBaris(Kelas $kelas, Carbon $tanggal, array $absensi, array $dikirim, User $pencatat): void
    {
        DB::transaction(function () use ($kelas, $tanggal, $absensi, $dikirim, $pencatat): void {
            // Dicari dengan whereDate, bukan updateOrCreate: kolom date bercast tersimpan dengan jam di SQLite, jadi
            // pencocokan string tanggal biasa tidak menemukan baris yang sudah ada.
            $ada = AbsensiMurid::query()->whereIn('murid_id', $dikirim)->whereDate('tanggal', $tanggal->toDateString())->lockForUpdate()->get()->keyBy('murid_id');

            foreach ($absensi as $baris) {
                $isi = ['kelas_id' => $kelas->id, 'status' => $baris['status'], 'keterangan' => $baris['keterangan'], 'dicatat_oleh' => $pencatat->id];
                $lama = $ada->get($baris['murid_id']);
                $lama === null
                    ? AbsensiMurid::query()->create(['murid_id' => $baris['murid_id'], 'tanggal' => $tanggal->toDateString(), ...$isi])
                    : $lama->update($isi);
            }
        });
    }

    /**
     * Jumlah per status per murid dalam satu bulan, dan jumlah hari yang sudah dicatat. Hanya tanggal yang menurut
     * aturan kelas sekarang adalah hari sekolah dan bukan libur yang dihitung: baris pada tanggal yang kemudian
     * dijadikan libur atau dilepas dari hari sekolah tetap tersimpan, dan ikut dihitung lagi kalau aturannya dikembalikan.
     *
     * @return array{bulan: string, hari_tercatat: int, murid: list<array{murid: array{id: int, nis: string, nama_lengkap: string}, hadir: int, izin: int, sakit: int, alpa: int}>}
     */
    public function rekap(Kelas $kelas, Carbon $bulan): array
    {
        $awal = $bulan->copy()->startOfMonth()->toDateString();
        $akhir = $bulan->copy()->endOfMonth()->toDateString();
        $libur = $kelas->libur()->whereDate('tanggal_mulai', '<=', $akhir)->whereDate('tanggal_selesai', '>=', $awal)->get();
        $absensi = AbsensiMurid::query()->where('kelas_id', $kelas->id)
            ->whereDate('tanggal', '>=', $awal)
            ->whereDate('tanggal', '<=', $akhir)
            ->get()
            ->filter(fn (AbsensiMurid $satu): bool => $kelas->hariSekolah($satu->tanggal)
                && ! $libur->contains(fn (LiburKelas $hariLibur): bool => $hariLibur->mencakup($satu->tanggal)));
        $perMurid = $absensi->groupBy('murid_id');

        return [
            'bulan' => $bulan->format('Y-m'),
            'hari_tercatat' => $absensi->map(fn (AbsensiMurid $satu): string => $satu->tanggal->toDateString())->unique()->count(),
            'murid' => $this->murid($kelas, $perMurid->keys()->all())->map(function (Murid $murid) use ($perMurid): array {
                $milik = $perMurid->get($murid->id, new Collection);

                return [
                    'murid' => $this->ringkasan($murid),
                    'hadir' => $milik->where('status', StatusAbsensiMurid::Hadir)->count(),
                    'izin' => $milik->where('status', StatusAbsensiMurid::Izin)->count(),
                    'sakit' => $milik->where('status', StatusAbsensiMurid::Sakit)->count(),
                    'alpa' => $milik->where('status', StatusAbsensiMurid::Alpa)->count(),
                ];
            })->values()->all(),
        ];
    }

    public function pdf(Kelas $kelas, Carbon $bulan): DokumenPdf
    {
        return Pdf::loadView('pdf.rekap-absensi-murid', $this->isiPdf($kelas, $bulan))
            ->setPaper('a4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function isiPdf(Kelas $kelas, Carbon $bulan): array
    {
        return [
            'sekolah' => $this->pengaturan->kopSekolah(),
            'kelas' => $kelas->loadMissing(['tahunAjaran', 'waliKelas.user']),
            'bulan' => $bulan->translatedFormat('F Y'),
            'rekap' => $this->rekap($kelas, $bulan),
        ];
    }

    /** `absensi-murid-tk-a1-2026-10`, tanpa ekstensi. */
    public function namaFile(Kelas $kelas, Carbon $bulan): string
    {
        return 'absensi-murid-'.str($kelas->nama)->slug().'-'.$bulan->format('Y-m');
    }

    /**
     * @param  list<int>  $tambahan  murid yang punya absensi di kelas itu walau penempatannya tidak aktif lagi
     * @return Collection<int, Murid>
     */
    private function murid(Kelas $kelas, array $tambahan): Collection
    {
        return Murid::query()
            ->where(fn (Builder $murid) => $murid
                ->whereHas('kelasMurid', fn (Builder $penempatan) => $penempatan->where('kelas_id', $kelas->id)->where('status', StatusKelasMurid::Aktif))
                ->orWhereIn('id', $tambahan))
            ->orderBy('nama_lengkap')
            ->get(['id', 'nis', 'nama_lengkap']);
    }

    /**
     * @return array{id: int, nis: string, nama_lengkap: string}
     */
    private function ringkasan(Murid $murid): array
    {
        return ['id' => $murid->id, 'nis' => $murid->nis, 'nama_lengkap' => $murid->nama_lengkap];
    }
}
