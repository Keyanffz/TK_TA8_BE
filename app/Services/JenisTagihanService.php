<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\JenisTagihan;

/**
 * Nominal baru hanya berlaku untuk tagihan yang dibuat sesudahnya; tagihan yang sudah ada tidak diubah.
 */
class JenisTagihanService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data): JenisTagihan
    {
        return JenisTagihan::query()->create(['is_aktif' => true, ...$data]);
    }

    /**
     * Tahun ajaran dan periode tidak bisa diganti setelah ada tagihan, karena tagihan lama tercatat
     * di tahun ajaran dan periode itu.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function perbarui(JenisTagihan $jenisTagihan, array $data): JenisTagihan
    {
        $jenisTagihan->fill($data);

        if ($jenisTagihan->isDirty(['tahun_ajaran_id', 'periode']) && $jenisTagihan->tagihan()->exists()) {
            throw new BusinessRuleException("Jenis tagihan {$jenisTagihan->getOriginal('nama')} sudah dipakai di tagihan sehingga tahun ajaran dan periodenya tidak bisa diganti. Buat jenis tagihan baru.");
        }

        $jenisTagihan->save();

        return $jenisTagihan;
    }

    /**
     * @throws BusinessRuleException
     */
    public function hapus(JenisTagihan $jenisTagihan): void
    {
        if ($jenisTagihan->tagihan()->exists() || $jenisTagihan->keringanan()->exists()) {
            throw new BusinessRuleException("Jenis tagihan {$jenisTagihan->nama} sudah dipakai di tagihan atau keringanan sehingga tidak bisa dihapus. Nonaktifkan saja supaya tidak dipakai lagi.");
        }

        $jenisTagihan->delete();
    }
}
