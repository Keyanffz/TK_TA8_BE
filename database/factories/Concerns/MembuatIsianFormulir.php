<?php

namespace Database\Factories\Concerns;

use App\Enums\GolonganDarah;
use App\Enums\Hubungan;
use App\Enums\Imunisasi;
use App\Enums\Kewarganegaraan;
use App\Enums\StatusYatimPiatu;
use App\Models\Murid;
use App\Models\OrangTua;
use App\Models\Pendaftaran;

/**
 * Isian formulir data murid yang lengkap untuk murid dan pendaftaran: anak kandung yang tinggal bersama ayah dan ibu,
 * dengan ibu sebagai kontak utama.
 */
trait MembuatIsianFormulir
{
    use MembuatAlamatSemarang;

    /**
     * @return array<string, mixed>
     */
    protected function isianFormulirAnak(): array
    {
        $saudara = fake()->numberBetween(0, 2);

        return [
            ...$this->alamatFormulirSemarang(),
            'anak_ke' => $saudara + 1,
            'asal_sekolah' => null,
            'kewarganegaraan' => Kewarganegaraan::Wni,
            'jumlah_saudara_kandung' => $saudara,
            'jumlah_saudara_tiri' => 0,
            'jumlah_saudara_angkat' => 0,
            'status_yatim_piatu' => StatusYatimPiatu::Tidak,
            'bahasa_sehari_hari' => fake()->randomElement(['Jawa', 'Indonesia']),
            'jarak_rumah_km' => fake()->randomFloat(1, 0.3, 6),
            'golongan_darah' => fake()->randomElement(GolonganDarah::cases()),
            'riwayat_penyakit' => null,
            'tinggi_badan' => fake()->randomFloat(1, 95, 115),
            'berat_badan' => fake()->randomFloat(1, 13, 21),
            'imunisasi' => [Imunisasi::Bcg->value, Imunisasi::HepatitisB->value, Imunisasi::Polio->value, Imunisasi::Dpt->value, Imunisasi::CampakMr->value],
            'imunisasi_lainnya' => null,
            'ciri_khusus' => null,
            'kontak_utama' => Hubungan::Ibu,
        ];
    }

    protected function buatAyahDanIbu(Murid|Pendaftaran $pemilik): void
    {
        $kolom = $pemilik instanceof Murid ? 'murid_id' : 'pendaftaran_id';

        OrangTua::factory()->create([$kolom => $pemilik->id]);
        OrangTua::factory()->ibu()->create([$kolom => $pemilik->id]);
    }
}
