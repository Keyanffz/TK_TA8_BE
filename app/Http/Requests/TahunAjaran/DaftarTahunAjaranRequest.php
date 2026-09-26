<?php

namespace App\Http\Requests\TahunAjaran;

use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;

class DaftarTahunAjaranRequest extends FormRequest
{
    use MemvalidasiDaftar;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->aturanDaftar(),
            ...$this->aturanUrutan(['nama', 'tanggal_mulai']),
        ];
    }
}
