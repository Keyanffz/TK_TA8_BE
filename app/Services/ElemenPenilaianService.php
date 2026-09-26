<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ElemenPenilaian;

/**
 * Elemen penilaian rapor (A2.8). Rapor yang sudah dibuat menyimpan barisnya sendiri per elemen, jadi elemen
 * yang dinonaktifkan tidak hilang dari rapor lama; elemen baru hanya masuk ke rapor yang dibuat sesudahnya.
 */
class ElemenPenilaianService
{
    /**
     * Tanpa `urutan`, elemen ditaruh paling akhir.
     *
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data): ElemenPenilaian
    {
        return ElemenPenilaian::query()->create([
            'urutan' => (int) ElemenPenilaian::query()->max('urutan') + 1,
            'is_aktif' => true,
            ...$data,
        ]);
    }

    /**
     * @throws BusinessRuleException
     */
    public function hapus(ElemenPenilaian $elemen): void
    {
        if ($elemen->raporDetail()->exists()) {
            throw new BusinessRuleException("Elemen {$elemen->nama} sudah dipakai di rapor sehingga tidak bisa dihapus. Nonaktifkan elemen ini supaya tidak muncul di rapor baru.");
        }

        $elemen->delete();
    }
}
