<?php

namespace Database\Factories;

use App\Enums\Hubungan;
use App\Enums\JenisKelamin;
use App\Enums\StatusPendaftaran;
use App\Enums\Tingkat;
use App\Models\Pendaftaran;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use Database\Factories\Concerns\MembuatAlamatSemarang;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pendaftaran>
 */
class PendaftaranFactory extends Factory
{
    use MembuatAlamatSemarang;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $jenisKelamin = fake()->randomElement(JenisKelamin::cases());
        $namaDepan = $jenisKelamin === JenisKelamin::L ? fake()->firstNameMale() : fake()->firstNameFemale();
        $namaKeluarga = fake()->lastName();

        return [
            'kode' => 'PPDB-2027-'.fake()->unique()->numerify('####'),
            'wali_murid_id' => WaliMurid::factory(),
            'hubungan' => Hubungan::Ibu,
            'tahun_ajaran_id' => TahunAjaran::factory(),
            'tingkat_tujuan' => Tingkat::A,
            'nama_lengkap' => $namaDepan.' '.$namaKeluarga,
            'nama_panggilan' => $namaDepan,
            'jenis_kelamin' => $jenisKelamin,
            'tempat_lahir' => 'Semarang',
            'tanggal_lahir' => fake()->dateTimeBetween('2022-07-01', '2023-06-30')->format('Y-m-d'),
            // 3374: kode wilayah Kota Semarang di NIK.
            'nik' => '3374'.fake()->numerify('############'),
            'agama' => 'Islam',
            'alamat' => $this->alamatSemarang(),
            'nama_ayah' => fake()->firstNameMale().' '.$namaKeluarga,
            'pekerjaan_ayah' => fake()->randomElement(['Karyawan swasta', 'Wiraswasta', 'PNS', 'Pedagang']),
            'nama_ibu' => fake()->firstNameFemale(),
            'pekerjaan_ibu' => fake()->randomElement(['Ibu rumah tangga', 'Guru', 'Perawat', 'Karyawan swasta']),
            'no_hp' => fake()->numerify('081#########'),
            'status' => StatusPendaftaran::Diajukan,
            'catatan' => null,
            'diproses_oleh' => null,
            'diproses_at' => null,
            'murid_id' => null,
        ];
    }

    /**
     * Pendaftaran yang dikirim tanpa login, belum punya wali.
     */
    public function publik(): static
    {
        return $this->state(fn (): array => ['wali_murid_id' => null]);
    }
}
