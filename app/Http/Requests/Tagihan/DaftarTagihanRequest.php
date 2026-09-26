<?php

namespace App\Http\Requests\Tagihan;

use App\Enums\StatusTagihan;
use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DaftarTagihanRequest extends FormRequest
{
    use MemvalidasiDaftar;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->aturanDaftar(),
            ...$this->aturanUrutan(['jatuh_tempo', 'periode', 'created_at']),
            'filter' => ['sometimes', 'array:status,periode,kelas_id,murid_id,jenis_tagihan_id'],
            'filter.status' => ['sometimes', Rule::enum(StatusTagihan::class)],
            'filter.periode' => ['sometimes', 'date_format:Y-m'],
            'filter.kelas_id' => ['sometimes', 'integer'],
            'filter.murid_id' => ['sometimes', 'integer'],
            'filter.jenis_tagihan_id' => ['sometimes', 'integer'],
        ];
    }
}
