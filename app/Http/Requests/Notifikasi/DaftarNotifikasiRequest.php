<?php

namespace App\Http\Requests\Notifikasi;

use App\Http\Requests\Concerns\MemvalidasiDaftar;
use Illuminate\Foundation\Http\FormRequest;

class DaftarNotifikasiRequest extends FormRequest
{
    use MemvalidasiDaftar;

    protected function prepareForValidation(): void
    {
        $this->normalkanFilterBoolean(['dibaca']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => $this->aturanDaftar()['per_page'],
            'filter' => ['sometimes', 'array:dibaca'],
            'filter.dibaca' => ['sometimes', 'boolean'],
        ];
    }
}
