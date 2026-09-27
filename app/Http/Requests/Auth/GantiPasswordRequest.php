<?php

namespace App\Http\Requests\Auth;

use App\Models\Murid;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class GantiPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults(), Rule::notIn($this->passwordAwalAnak())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.not_in' => 'Password baru tidak boleh sama dengan tanggal lahir anak.',
        ];
    }

    /**
     * Password awal wali (tanggal lahir setiap anak yang tertaut) mudah ditebak, jadi tidak boleh dipakai lagi.
     *
     * @return list<string>
     */
    private function passwordAwalAnak(): array
    {
        /** @var User $user */
        $user = $this->user();

        if ($user->loadMissing('waliMurid')->waliMurid === null) {
            return [];
        }

        return $user->waliMurid->murid()->get()->map(fn (Murid $anak): string => $anak->passwordAwalWali())->values()->all();
    }
}
