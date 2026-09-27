<?php

namespace App\Http\Requests\Auth;

use App\Enums\Perangkat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginWaliRequest extends FormRequest
{
    /**
     * NIS ditulis dengan huruf besar (`TA2026…`), tetapi wali sering mengetiknya dengan huruf kecil atau spasi.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => self::normalkanUsername($this->input('username'))]);
        }
    }

    public static function normalkanUsername(string $username): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $username));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string'],
            'perangkat' => ['sometimes', Rule::enum(Perangkat::class)],
        ];
    }

    public function perangkat(): Perangkat
    {
        return $this->enum('perangkat', Perangkat::class) ?? Perangkat::Web;
    }
}
