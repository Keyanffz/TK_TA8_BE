<?php

namespace App\Http\Requests\Keringanan;

use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;

class DaftarKeringananRequest extends FormRequest
{
    use MemvalidasiDaftar;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->aturanDaftar(),
            ...$this->aturanUrutan(['berlaku_mulai', 'created_at']),
            'filter' => ['sometimes', 'array:murid_id,jenis_tagihan_id'],
            'filter.murid_id' => ['sometimes', 'integer'],
            'filter.jenis_tagihan_id' => ['sometimes', 'integer'],
        ];
    }
}
