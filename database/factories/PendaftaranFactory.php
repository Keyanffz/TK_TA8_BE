<?php

namespace Database\Factories;

use App\Enums\Hubungan;
use App\Enums\JenisKelamin;
use App\Enums\StatusPendaftaran;
use App\Enums\Tingkat;
use App\Models\Pendaftaran;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use Database\Factories\Concerns\MembuatIsianFormulir;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pendaftaran>
 */
class PendaftaranFactory extends Factory
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
            'kode' => 'PPDB-2027-'.fake()->unique()->numerify('####'),
            'wali_murid_id' => WaliMurid::factory(),
            'hubungan' => Hubungan::Ibu,
            'tahun_ajaran_id' => TahunAjaran::factory(),
            'tingkat_tujuan' => Tingkat::A,
            'nama_lengkap' => $namaDepan.' '.fake()->lastName(),
            'nama_panggilan' => $namaDepan,
            'jenis_kelamin' => $jenisKelamin,
            'tempat_lahir' => 'Semarang',
            'tanggal_lahir' => fake()->dateTimeBetween('2022-07-01', '2023-06-30')->format('Y-m-d'),
            // 3374: kode wilayah Kota Semarang di NIK.
            'nik' => '3374'.fake()->numerify('############'),
            'agama' => 'Islam',
            ...$this->isianFormulirAnak(),
            'status' => StatusPendaftaran::Diajukan,
            'catatan' => null,
            'diproses_oleh' => null,
            'diproses_at' => null,
            'murid_id' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Pendaftaran $pendaftaran) => $this->buatAyahDanIbu($pendaftaran));
    }

    /**
     * Pendaftaran yang dikirim tanpa login, belum punya wali dan tanpa `hubungan`.
     */
    public function publik(): static
    {
        return $this->state(fn (): array => ['wali_murid_id' => null, 'hubungan' => null]);
    }
}
