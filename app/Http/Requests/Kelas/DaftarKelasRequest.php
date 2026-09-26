<?php

namespace App\Http\Requests\Kelas;

use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;

class DaftarKelasRequest extends FormRequest
{
    use MemvalidasiDaftar;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->aturanDaftar(),
            ...$this->aturanUrutan(['nama', 'created_at']),
            'filter' => ['sometimes', 'array:tahun_ajaran_id'],
            'filter.tahun_ajaran_id' => ['sometimes', 'integer'],
        ];
    }
}
