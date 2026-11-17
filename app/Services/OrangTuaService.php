<?php

namespace App\Services;

use App\Enums\HubunganWali;
use App\Enums\Pekerjaan;
use App\Models\Murid;
use App\Models\OrangTua;
use App\Models\Pendaftaran;
use App\Support\FormulirMurid;

/**
 * Isian ayah, ibu, dan wali milik satu murid atau satu pendaftaran (tabel `orang_tua`).
 */
class OrangTuaService
{
    /**
     * Peran yang tidak dikirim tidak berubah. Peran yang dikirim diganti seluruhnya; objek yang semua isiannya kosong
     * menghapus baris peran itu.
     *
     * @param  array<string, mixed>  $data  hasil validasi `FormulirMurid::aturanOrangTua()`
     */
    public function simpan(Murid|Pendaftaran $pemilik, array $data): void
    {
        foreach (FormulirMurid::PERAN as $peran) {
            if (! array_key_exists($peran, $data)) {
                continue;
            }

            $isian = $this->bersihkan($peran, is_array($data[$peran]) ? $data[$peran] : []);

            if ($isian === null) {
                $pemilik->orangTua()->where('peran', $peran)->delete();
            } else {
                $pemilik->orangTua()->updateOrCreate(['peran' => $peran], $isian);
            }
        }

        $pemilik->unsetRelation('orangTua');
    }

    /**
     * Isian orang tua per peran, untuk disalin ke murid saat pendaftaran diterima.
     *
     * @return array<string, array<string, mixed>>
     */
    public function isianDari(Pendaftaran $pendaftaran): array
    {
        return $pendaftaran->orangTua()->get()
            ->mapWithKeys(fn (OrangTua $orang): array => [$orang->peran->value => $orang->isian()])
            ->all();
    }

    /**
     * Isian yang tidak berlaku untuk peran atau pilihannya dikosongkan, misalnya alamat rumah saat dicentang sama
     * dengan alamat anak.
     *
     * @param  array<string, mixed>  $isian
     * @return array<string, mixed>|null null kalau semua isian kosong
     */
    private function bersihkan(string $peran, array $isian): ?array
    {
        $bersih = [...array_fill_keys(OrangTua::ISIAN, null), ...array_intersect_key($isian, array_flip(OrangTua::ISIAN))];
        $bersih['alamat_sama_dengan_anak'] = filter_var($bersih['alamat_sama_dengan_anak'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($bersih['alamat_sama_dengan_anak']) {
            $bersih['alamat_rumah'] = null;
        }
        if ($peran === 'wali') {
            $bersih['status'] = null;
        } else {
            $bersih['hubungan'] = null;
        }
        if ($bersih['hubungan'] !== HubunganWali::Lainnya->value) {
            $bersih['hubungan_lainnya'] = null;
        }
        if ($bersih['pekerjaan'] !== Pekerjaan::Lainnya->value) {
            $bersih['pekerjaan_lainnya'] = null;
        }

        $terisi = array_filter($bersih, fn (mixed $nilai, string $kolom): bool => $kolom !== 'alamat_sama_dengan_anak' && $nilai !== null && $nilai !== '', ARRAY_FILTER_USE_BOTH);

        return $terisi === [] && ! $bersih['alamat_sama_dengan_anak'] ? null : $bersih;
    }
}
