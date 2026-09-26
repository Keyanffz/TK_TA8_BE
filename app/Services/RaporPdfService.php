<?php

namespace App\Services;

use App\Models\Rapor;
use App\Models\RaporDetail;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DokumenPdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Rapor PDF (A4 tegak) dengan kop sekolah, identitas murid, deskripsi dan foto per elemen, catatan guru, dan
 * tanda tangan wali kelas serta Kepala Sekolah. Rapor yang belum terbit diberi tanda pratinjau.
 */
class RaporPdfService
{
    public function __construct(private readonly PengaturanService $pengaturan) {}

    public function buat(Rapor $rapor): DokumenPdf
    {
        $rapor->loadMissing(['murid', 'kelas', 'tahunAjaran', 'pembuat.user', 'penyetuju', 'detail.elemenPenilaian']);
        $diskPrivat = Storage::disk(MediaService::DISK_PRIVAT);

        $detail = $rapor->detail->sortBy(fn (RaporDetail $satu): int => $satu->elemenPenilaian->urutan)->values()
            ->map(fn (RaporDetail $satu): array => [
                'elemen' => $satu->elemenPenilaian->nama,
                'deskripsi' => $satu->deskripsi,
                'foto' => $satu->foto_path !== null && $diskPrivat->exists($satu->foto_path) ? $diskPrivat->path($satu->foto_path) : null,
            ]);

        return Pdf::loadView('pdf.rapor', [
            'sekolah' => $this->pengaturan->kopSekolah(),
            'rapor' => $rapor,
            'detail' => $detail,
            'kepalaSekolah' => $rapor->penyetuju?->name ?? User::query()->kepalaSekolahAktif()->value('name'),
        ])
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true);
    }

    public function namaFile(Rapor $rapor): string
    {
        return 'rapor-'.Str::slug("{$rapor->murid->nis} {$rapor->tahunAjaran->nama} semester {$rapor->semester}").'.pdf';
    }
}
