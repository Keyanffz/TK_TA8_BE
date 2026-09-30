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
            /** ID token (JWT) dari Google Identity Services, yaitu field `credential` di callback tombol Google. */
            'credential' => ['required', 'string', 'max:4096'],
            'perangkat' => ['sometimes', Rule::enum(Perangkat::class)],
        ];
    }

    public function perangkat(): Perangkat
    {
        return $this->enum('perangkat', Perangkat::class) ?? Perangkat::Web;
    }
}
