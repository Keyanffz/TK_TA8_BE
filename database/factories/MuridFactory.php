<?php

namespace Database\Factories;

use App\Enums\JenisKelamin;
use App\Enums\StatusMurid;
use App\Models\Murid;
use Database\Factories\Concerns\MembuatIsianFormulir;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Murid>
 */
class MuridFactory extends Factory
{
    use MembuatIsianFormulir;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $jenisKelamin = fake()->randomElement(JenisKelamin::cases());
        $namaDepan = $jenisKelamin === JenisKelamin::L ? fake()->firstNameMale() : fake()->firstNameFemale();

        return [
            'nis' => 'TA2026'.fake()->unique()->numerify('####'),
            'nisn' => null,
            // 3374: kode wilayah Kota Semarang di NIK.
            'nik' => '3374'.fake()->unique()->numerify('############'),
            'nama_lengkap' => $namaDepan.' '.fake()->lastName(),
            'nama_panggilan' => $namaDepan,
            'jenis_kelamin' => $jenisKelamin,
            'tempat_lahir' => 'Semarang',
            'tanggal_lahir' => fake()->dateTimeBetween('-6 years', '-4 years')->format('Y-m-d'),
            'agama' => 'Islam',
            'alamat' => $this->alamatSemarang(),
            'anak_ke' => fake()->numberBetween(1, 3),
            'foto_path' => null,
            'catatan_khusus' => null,
            'status' => StatusMurid::Aktif,
            'tanggal_masuk' => '2026-07-13',
            'tanggal_keluar' => null,
        ];
    }

    /**
     * Formulir data murid terisi lengkap beserta ayah dan ibu. Tanpa state ini murid seperti data lama: kolom formulir
     * kosong dan `data_lengkap = false`.
     */
    public function lengkap(): static
    {
        return $this->state(fn (): array => $this->isianFormulirAnak())
            ->afterCreating(function (Murid $murid): void {
                $this->buatAyahDanIbu($murid);
                $murid->perbaruiKelengkapan();
            });
    }
}
