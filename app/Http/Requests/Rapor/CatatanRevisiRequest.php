<?php

namespace App\Http\Requests\Rapor;

use Illuminate\Foundation\Http\FormRequest;

class CatatanRevisiRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'catatan' => ['required', 'string', 'max:1000'],
        ];
    }

    public function catatan(): string
    {
        return $this->string('catatan')->trim()->toString();
    }
}
