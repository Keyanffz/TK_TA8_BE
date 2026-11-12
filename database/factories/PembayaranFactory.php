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
            'metode' => MetodeBayar::Tunai,
            'jumlah' => fn (array $atribut) => Tagihan::query()->findOrFail($atribut['tagihan_id'])->total,
            'tanggal_bayar' => '2026-09-05',
            'bukti_path' => null,
            'bank_pengirim' => null,
            'nama_pengirim' => null,
            'status' => StatusPembayaran::Diterima,
            'alasan_penolakan' => null,
            'diverifikasi_oleh' => null,
            'diverifikasi_at' => null,
        ];
    }

    /**
     * Transfer dengan bukti dari sebelum pembayaran hanya tunai; masih tampil di riwayat.
     */
    public function transferLama(StatusPembayaran $status = StatusPembayaran::Diterima): static
    {
        return $this->state([
            'metode' => MetodeBayar::Transfer,
            'bukti_path' => 'bukti-bayar/'.Str::uuid().'.jpg',
            'bank_pengirim' => 'BRI',
            'nama_pengirim' => 'Siti Maryam',
            'status' => $status,
        ]);
    }
}
