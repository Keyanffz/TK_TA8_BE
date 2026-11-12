<?php

namespace App\Http\Requests\Pembayaran;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /tagihan/{id}/pembayaran`: pembayaran tunai yang diterima petugas di sekolah. `jumlah` boleh sebagian
 * dari tagihan (cicilan); batas atasnya, sisa tagihan, dicek di service terhadap data tagihan.
 */
class BayarTagihanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'jumlah' => ['required', 'integer', 'min:1'],
            'tanggal_bayar' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jumlah.min' => 'Jumlah pembayaran minimal Rp 1.',
            'tanggal_bayar.before_or_equal' => 'Tanggal bayar tidak boleh setelah hari ini.',
        ];
    }

    public function jumlah(): int
    {
        return $this->integer('jumlah');
    }

    public function tanggalBayar(): string
    {
        return $this->string('tanggal_bayar')->toString();
    }
}
