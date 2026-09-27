<?php

namespace App\Http\Requests\Kegiatan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sama dengan `PUT /galeri-foto/{id}`: field yang tidak dikirim tidak berubah.
 */
class PerbaruiFotoKegiatanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'caption' => ['sometimes', 'nullable', 'string', 'max:255'],
            'urutan' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
