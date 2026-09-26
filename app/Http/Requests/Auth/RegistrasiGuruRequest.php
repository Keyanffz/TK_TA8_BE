<?php

namespace App\Http\Requests\Auth;

use App\Enums\JenisKelamin;
use App\Rules\NomorHp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegistrasiGuruRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'no_hp' => ['required', new NomorHp],
            'jenis_kelamin' => ['required', Rule::enum(JenisKelamin::class)],
        ];
    }
}
