<?php

namespace App\Http\Requests\Kegiatan;

use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;

class DaftarKegiatanRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:kelas_id'],
            'filter.kelas_id' => ['sometimes', 'integer'],
        ];
    }
}
