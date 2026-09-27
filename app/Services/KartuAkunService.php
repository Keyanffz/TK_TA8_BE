<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Exceptions\BusinessRuleException;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DokumenPdf;

/**
 * Kartu akun wali murid ukuran A6 untuk dibagikan sekolah: NIS anak sebagai username dan keterangan password awal.
 * Password tidak pernah ditulis di kartu.
 */
class KartuAkunService
{
    public function __construct(private readonly PengaturanService $pengaturan) {}

    /**
     * @throws BusinessRuleException
     */
    public function buat(Murid $murid): DokumenPdf
    {
        return Pdf::loadView('pdf.kartu-akun', $this->isi($murid))
            ->setPaper('a6', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
    }

    /**
     * Kartu hanya berlaku kalau akun dengan username NIS murid ini ada dan aktif. Akun otomatis yang dinonaktifkan
     * karena anaknya ditambahkan ke akun kakak/adik tidak bisa dipakai login lagi.
     *
     * @return array{sekolah: array{nama: string, alamat: string, telepon: string, email: string, logo: string|null}, murid: Murid, kelas: Kelas|null, alamat_website: string}
     *
     * @throws BusinessRuleException
     */
    public function isi(Murid $murid): array
    {
        $akunAktif = User::query()
            ->where('username', $murid->nis)
            ->where('role', Role::WaliMurid)
            ->where('status', StatusAkun::Aktif)
            ->exists();

        if (! $akunAktif) {
            throw new BusinessRuleException("Tidak ada akun wali aktif dengan username {$murid->nis}. {$murid->nama_panggilan} mungkin sudah ditambahkan ke akun wali kakak/adiknya; lihat daftar wali di detail murid.");
        }

        return [
            'sekolah' => $this->pengaturan->kopSekolah(),
            'murid' => $murid,
            'kelas' => $murid->loadMissing('kelasAktif')->kelasAktif->first(),
            'alamat_website' => rtrim((string) config('app.frontend_url'), '/').'/login',
        ];
    }

    public function namaFile(Murid $murid): string
    {
        return "kartu-akun-{$murid->nis}.pdf";
    }
}
