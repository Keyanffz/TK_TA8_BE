<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WaliMurid;
use Database\Factories\Concerns\MembuatAlamatSemarang;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaliMurid>
 */
class WaliMuridFactory extends Factory
{
    use MembuatAlamatSemarang;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->waliMurid(),
            // 3374: kode wilayah Kota Semarang di NIK.
            'nik' => '3374'.fake()->numerify('############'),
            'pekerjaan' => fake()->randomElement([
                'Karyawan swasta', 'Wiraswasta', 'PNS', 'Guru', 'Pedagang', 'Perawat',
                'Ibu rumah tangga', 'Buruh pabrik', 'Driver ojek daring', 'TNI/Polri',
            ]),
            'alamat' => $this->alamatSemarang(),
            'profil_lengkap' => true,
        ];
    }

    public function profilBelumLengkap(): static
    {
        return $this->state(fn (): array => [
            'nik' => null,
            'pekerjaan' => null,
            'alamat' => null,
            'profil_lengkap' => false,
        ]);
    }
}
