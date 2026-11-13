<?php

namespace App\Services;

use App\Enums\StatusRapor;
use App\Exceptions\BusinessRuleException;
use App\Models\Kelas;
use App\Models\Rapor;
use App\Models\RaporDetail;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DokumenPdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Rapor PDF (A4 tegak) dengan kop sekolah, identitas murid, deskripsi dan foto per elemen, catatan guru, dan
 * tanda tangan wali kelas serta Kepala Sekolah. Rapor yang belum terbit diberi tanda pratinjau. Ekspor satu kelas
 * menggabungkan rapor terbit dengan template yang sama.
 */
class RaporPdfService
{
    public function __construct(private readonly PengaturanService $pengaturan) {}

    public function buat(Rapor $rapor): DokumenPdf
    {
        return Pdf::loadView('pdf.rapor', $this->isi($rapor))
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true);
    }

    /**
     * Semua rapor `terbit` satu kelas dan semester di tahun ajaran kelas itu, urut nama murid, tiap murid mulai di
     * halaman baru. Memakai template rapor yang sama dengan PDF per murid; template kertas sekolah belum ada.
     *
     * @throws BusinessRuleException
     */
    public function buatKelas(Kelas $kelas, int $semester): DokumenPdf
    {
        return Pdf::loadView('pdf.rapor-kelas', $this->isiKelas($kelas, $semester))
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true);
    }

    /**
     * @return array{kelas: Kelas, semester: int, semuaRapor: list<array<string, mixed>>}
     *
     * @throws BusinessRuleException
     */
    public function isiKelas(Kelas $kelas, int $semester): array
    {
        $rapor = Rapor::query()
            ->where('kelas_id', $kelas->id)
            ->where('tahun_ajaran_id', $kelas->tahun_ajaran_id)
            ->where('semester', $semester)
            ->where('status', StatusRapor::Terbit)
            ->with('murid')
            ->get()
            ->sortBy(fn (Rapor $satu): string => $satu->murid->nama_lengkap)
            ->values();

        if ($rapor->isEmpty()) {
            throw new BusinessRuleException("Belum ada rapor terbit di {$kelas->nama} semester {$semester}. Terbitkan rapor dulu, lalu unduh lagi.");
        }

        return ['kelas' => $kelas, 'semester' => $semester, 'semuaRapor' => $rapor->map($this->isi(...))->all()];
    }

    /**
     * Data template untuk satu rapor.
     *
     * @return array{sekolah: array<string, string|null>, rapor: Rapor, detail: Collection<int, array{elemen: string, deskripsi: string|null, foto: string|null}>, kepalaSekolah: string|null}
     */
    public function isi(Rapor $rapor): array
    {
        $rapor->loadMissing(['murid', 'kelas', 'tahunAjaran', 'pembuat.user', 'penyetuju', 'detail.elemenPenilaian']);
        $diskPrivat = Storage::disk(MediaService::DISK_PRIVAT);

        $detail = $rapor->detail->sortBy(fn (RaporDetail $satu): int => $satu->elemenPenilaian->urutan)->values()
            ->map(fn (RaporDetail $satu): array => [
                'elemen' => $satu->elemenPenilaian->nama,
                'deskripsi' => $satu->deskripsi,
                'foto' => $satu->foto_path !== null && $diskPrivat->exists($satu->foto_path) ? $diskPrivat->path($satu->foto_path) : null,
            ]);

        return [
            'sekolah' => $this->pengaturan->kopSekolah(),
            'rapor' => $rapor,
            'detail' => $detail,
            'kepalaSekolah' => $rapor->penyetuju->name ?? User::query()->kepalaSekolahAktif()->value('name'),
        ];
    }

    public function namaFile(Rapor $rapor): string
    {
        return 'rapor-'.Str::slug("{$rapor->murid->nis} {$rapor->tahunAjaran->nama} semester {$rapor->semester}").'.pdf';
    }

    /** `rapor-tk-a1-2026-2027-semester-1.pdf`. */
    public function namaFileKelas(Kelas $kelas, int $semester): string
    {
        // Garis miring "2026/2027" dibuang Str::slug, jadi diganti spasi dulu supaya tahunnya tetap terbaca.
        return 'rapor-'.Str::slug("{$kelas->nama} ".str_replace('/', ' ', $kelas->tahunAjaran->nama)." semester {$semester}").'.pdf';
    }
}
