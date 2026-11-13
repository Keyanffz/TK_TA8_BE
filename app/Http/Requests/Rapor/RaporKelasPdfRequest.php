<?php

namespace App\Http\Requests\Rapor;

use Illuminate\Foundation\Http\FormRequest;

class RaporKelasPdfRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'semester' => ['required', 'integer', 'in:1,2'],
        ];
    }

    public function semester(): int
    {
        return $this->integer('semester');
    }
}
