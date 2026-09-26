<?php

namespace App\Http\Requests\Auth;

use App\Enums\Perangkat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginGoogleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string'],
            'perangkat' => ['sometimes', Rule::enum(Perangkat::class)],
        ];
    }

    public function perangkat(): Perangkat
    {
        return $this->enum('perangkat', Perangkat::class) ?? Perangkat::Web;
    }
}
