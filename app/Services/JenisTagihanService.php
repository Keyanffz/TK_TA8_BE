<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\JenisTagihan;
use App\Models\User;

/**
 * Nominal baru hanya berlaku untuk tagihan yang dibuat sesudahnya; tagihan yang sudah ada tidak diubah. Setiap
 * perubahan dicatat di log aktivitas `jenis_tagihan`.
 */
class JenisTagihanService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data, User $pelaku): JenisTagihan
    {
        $jenisTagihan = JenisTagihan::query()->create(['is_aktif' => true, ...$data]);
        $this->catatLog($jenisTagihan, $pelaku, 'dibuat', "Membuat jenis tagihan {$jenisTagihan->nama}");

        return $jenisTagihan;
    }

    /**
     * Tahun ajaran dan periode tidak bisa diganti setelah ada tagihan, karena tagihan lama tercatat
     * di tahun ajaran dan periode itu.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function perbarui(JenisTagihan $jenisTagihan, array $data, User $pelaku): JenisTagihan
    {
        $jenisTagihan->fill($data);

        if ($jenisTagihan->isDirty(['tahun_ajaran_id', 'periode']) && $jenisTagihan->tagihan()->exists()) {
            throw new BusinessRuleException("Jenis tagihan {$jenisTagihan->getOriginal('nama')} sudah dipakai di tagihan sehingga tahun ajaran dan periodenya tidak bisa diganti. Buat jenis tagihan baru.");
        }

        $berubah = array_keys($jenisTagihan->getDirty());
        $jenisTagihan->save();

        if ($berubah !== []) {
            $this->catatLog($jenisTagihan, $pelaku, 'diubah', "Mengubah jenis tagihan {$jenisTagihan->nama}: ".implode(', ', $berubah));
        }

        return $jenisTagihan;
    }

    /**
     * @throws BusinessRuleException
     */
    public function hapus(JenisTagihan $jenisTagihan, User $pelaku): void
    {
        if ($jenisTagihan->tagihan()->exists() || $jenisTagihan->keringanan()->exists()) {
            throw new BusinessRuleException("Jenis tagihan {$jenisTagihan->nama} sudah dipakai di tagihan atau keringanan sehingga tidak bisa dihapus. Nonaktifkan saja supaya tidak dipakai lagi.");
        }

        $jenisTagihan->delete();
        $this->catatLog($jenisTagihan, $pelaku, 'dihapus', "Menghapus jenis tagihan {$jenisTagihan->nama}");
    }

    private function catatLog(JenisTagihan $jenisTagihan, User $pelaku, string $event, string $pesan): void
    {
        activity('jenis_tagihan')->causedBy($pelaku)->performedOn($jenisTagihan)->event($event)
            ->withProperties(['nominal' => $jenisTagihan->nominal, 'periode' => $jenisTagihan->periode->value, 'is_aktif' => $jenisTagihan->is_aktif])
            ->log($pesan);
    }
}
