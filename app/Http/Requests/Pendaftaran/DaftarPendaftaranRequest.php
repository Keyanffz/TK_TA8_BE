<?php

namespace App\Http\Requests\Pendaftaran;

use App\Enums\StatusPendaftaran;
use App\Enums\Tingkat;
use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DaftarPendaftaranRequest extends FormRequest
{
    use MemvalidasiDaftar;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->aturanDaftar(),
            ...$this->aturanUrutan(['created_at', 'nama']),
            'filter' => ['sometimes', 'array:status,tahun_ajaran_id,tingkat_tujuan'],
            'filter.status' => ['sometimes', Rule::enum(StatusPendaftaran::class)],
            'filter.tahun_ajaran_id' => ['sometimes', 'integer'],
            'filter.tingkat_tujuan' => ['sometimes', Rule::enum(Tingkat::class)],
        ];
    }
}
