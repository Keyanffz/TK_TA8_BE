<?php

namespace App\Http\Requests\Wali;

use App\Rules\NomorHp;
use Illuminate\Foundation\Http\FormRequest;

class LengkapiProfilWaliRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'no_hp' => ['required', new NomorHp],
            'alamat' => ['required', 'string', 'max:500'],
            'pekerjaan' => ['required', 'string', 'max:100'],
            'nik' => ['nullable', 'digits:16'],
        ];
    }
}
