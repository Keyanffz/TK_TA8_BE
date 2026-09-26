<?php

namespace App\Http\Requests\Galeri;

use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;

class DaftarGaleriRequest extends FormRequest
{
    use MemvalidasiDaftar;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->aturanDaftar(),
            ...$this->aturanUrutan(['tanggal', 'created_at']),
            'filter' => ['sometimes', 'array:is_publik'],
            'filter.is_publik' => ['sometimes', 'boolean'],
        ];
    }
}
