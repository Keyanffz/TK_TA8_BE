<?php

namespace App\Http\Requests\Pembayaran;

use App\Enums\MetodeBayar;
use App\Enums\StatusPembayaran;
use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DaftarPembayaranRequest extends FormRequest
{
    use MemvalidasiDaftar;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->aturanDaftar(),
            ...$this->aturanUrutan(['tanggal_bayar', 'created_at']),
            'filter' => ['sometimes', 'array:status,metode,tanggal'],
            'filter.status' => ['sometimes', Rule::enum(StatusPembayaran::class)],
            'filter.metode' => ['sometimes', Rule::enum(MetodeBayar::class)],
            'filter.tanggal' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
