<?php

namespace Database\Factories;

use App\Enums\Hubungan;
use App\Enums\Kewarganegaraan;
use App\Enums\Pekerjaan;
use App\Enums\PendidikanTerakhir;
use App\Enums\Penghasilan;
use App\Enums\StatusOrangTua;
use App\Models\OrangTua;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Isian lengkap ayah kandung yang tinggal serumah dengan anak. Pemilik (`murid_id` atau `pendaftaran_id`) diisi
 * pemanggil.
 *
 * @extends Factory<OrangTua>
 */
class OrangTuaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'peran' => Hubungan::Ayah,
            'status' => StatusOrangTua::Kandung,
            'nama' => fake()->firstNameMale().' '.fake()->lastName(),
            'tempat_lahir' => fake()->randomElement(['Semarang', 'Kendal', 'Demak', 'Ungaran', 'Salatiga']),
            'tanggal_lahir' => fake()->dateTimeBetween('1983-01-01', '1997-12-31')->format('Y-m-d'),
            'agama' => 'Islam',
            'kewarganegaraan' => Kewarganegaraan::Wni,
            'pendidikan_terakhir' => fake()->randomElement([PendidikanTerakhir::Slta, PendidikanTerakhir::D1D3, PendidikanTerakhir::D4S1]),
            'pekerjaan' => fake()->randomElement([Pekerjaan::KaryawanSwasta, Pekerjaan::Wiraswasta, Pekerjaan::PnsTniPolri, Pekerjaan::Pedagang]),
            'penghasilan' => fake()->randomElement([Penghasilan::Sampai2Juta, Penghasilan::Sampai5Juta, Penghasilan::Sampai20Juta]),
            'alamat_sama_dengan_anak' => true,
            'alamat_rumah' => null,
            'alamat_kantor' => null,
            'no_hp' => fake()->numerify('081#########'),
        ];
    }

    public function ibu(): static
    {
        return $this->state(fn (): array => [
            'peran' => Hubungan::Ibu,
            'nama' => fake()->firstNameFemale().' '.fake()->lastName(),
            'pekerjaan' => fake()->randomElement([Pekerjaan::MengurusRumahTangga, Pekerjaan::KaryawanSwasta, Pekerjaan::Wiraswasta]),
        ]);
    }
}
