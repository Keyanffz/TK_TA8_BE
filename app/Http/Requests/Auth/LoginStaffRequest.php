<?php

namespace App\Http\Requests\Auth;

use App\Enums\Perangkat;
use App\Http\Requests\Concerns\MenormalkanEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginStaffRequest extends FormRequest
{
    use MenormalkanEmail;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'perangkat' => ['sometimes', Rule::enum(Perangkat::class)],
        ];
    }

    public function perangkat(): Perangkat
    {
        return $this->enum('perangkat', Perangkat::class) ?? Perangkat::Web;
    }
}
