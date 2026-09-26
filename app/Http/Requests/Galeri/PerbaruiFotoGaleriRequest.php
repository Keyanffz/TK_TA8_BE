<?php

namespace App\Http\Requests\Galeri;

use Illuminate\Foundation\Http\FormRequest;

class PerbaruiFotoGaleriRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'caption' => ['nullable', 'string', 'max:255'],
            'urutan' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
