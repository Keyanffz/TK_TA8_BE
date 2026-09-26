<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Pengaturan;
use App\Models\TahunAjaran;
use Illuminate\Support\Facades\DB;

/**
 * Tepat satu tahun ajaran aktif (B6.4). Tahun ajaran pertama yang dibuat langsung aktif supaya aturan itu
 * berlaku sejak awal; berikutnya dibuat tidak aktif dan diaktifkan lewat `aktifkan()`.
 */
class TahunAjaranService
{
    private const SEMESTER_AWAL = 1;

    /**
     * @param  array{nama: string, tanggal_mulai: string, tanggal_selesai: string, semester_aktif?: int}  $data
     */
    public function buat(array $data): TahunAjaran
    {
        return DB::transaction(function () use ($data): TahunAjaran {
            $adaYangAktif = TahunAjaran::query()->aktif()->lockForUpdate()->exists();

            return TahunAjaran::query()->create([
                'semester_aktif' => self::SEMESTER_AWAL,
                ...$data,
                'is_aktif' => ! $adaYangAktif,
            ]);
        });
    }

    /**
     * @param  array{nama: string, tanggal_mulai: string, tanggal_selesai: string, semester_aktif?: int}  $data
     */
    public function perbarui(TahunAjaran $tahunAjaran, array $data): TahunAjaran
    {
        $tahunAjaran->update($data);

        return $tahunAjaran;
    }

    /**
     * @throws BusinessRuleException
     */
    public function hapus(TahunAjaran $tahunAjaran): void
    {
        if ($tahunAjaran->is_aktif) {
            throw new BusinessRuleException('Tahun ajaran yang sedang aktif tidak bisa dihapus. Aktifkan tahun ajaran lain terlebih dahulu.');
        }

        $dipakai = $tahunAjaran->kelas()->exists()
            || $tahunAjaran->tagihan()->exists()
            || $tahunAjaran->jenisTagihan()->exists()
            || $tahunAjaran->pendaftaran()->exists();

        if ($dipakai) {
            throw new BusinessRuleException("Tahun ajaran {$tahunAjaran->nama} sudah punya kelas, tagihan, atau pendaftar PPDB sehingga tidak bisa dihapus.");
        }

        if ((int) Pengaturan::query()->where('kunci', 'ppdb.tahun_ajaran_id')->value('nilai') === $tahunAjaran->id) {
            throw new BusinessRuleException("Tahun ajaran {$tahunAjaran->nama} dipakai sebagai tujuan PPDB. Ganti tahun ajaran PPDB di pengaturan terlebih dahulu.");
        }

        $tahunAjaran->delete();
    }

    public function aktifkan(TahunAjaran $tahunAjaran): TahunAjaran
    {
        DB::transaction(function () use ($tahunAjaran): void {
            TahunAjaran::query()->lockForUpdate()->get(['id']);
            TahunAjaran::query()->whereKeyNot($tahunAjaran->id)->where('is_aktif', true)->update(['is_aktif' => false]);
            $tahunAjaran->update(['is_aktif' => true]);
        });

        return $tahunAjaran;
    }
}
