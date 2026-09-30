<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\MenormalkanEmail;
use Illuminate\Foundation\Http\FormRequest;

class LupaPasswordRequest extends FormRequest
{
    use MenormalkanEmail;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }
}
