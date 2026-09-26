<?php

namespace Database\Factories;

use App\Enums\MetodeBayar;
use App\Enums\StatusPembayaran;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Pembayaran>
 */
class PembayaranFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode' => 'PAY-20260905-'.fake()->unique()->numerify('#####'),
            'tagihan_id' => Tagihan::factory(),
            'dibayar_oleh' => null,
            'metode' => MetodeBayar::Transfer,
            'jumlah' => fn (array $atribut) => Tagihan::query()->findOrFail($atribut['tagihan_id'])->total,
            'tanggal_bayar' => '2026-09-05',
            'bukti_path' => 'bukti-bayar/'.Str::uuid().'.jpg',
            'bank_pengirim' => fake()->randomElement(['BRI', 'BCA', 'Bank Jateng', 'Mandiri', 'BSI', 'BNI']),
            'nama_pengirim' => fake()->name(),
            'status' => StatusPembayaran::Menunggu,
            'alasan_penolakan' => null,
            'diverifikasi_oleh' => null,
            'diverifikasi_at' => null,
        ];
    }
}
