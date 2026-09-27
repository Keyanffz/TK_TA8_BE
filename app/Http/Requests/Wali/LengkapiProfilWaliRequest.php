<?php

namespace App\Http\Requests\Wali;

use App\Rules\NomorHp;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /wali/profil`: onboarding sekaligus ubah profil. Field yang tidak dikirim tidak berubah; nomor HP,
 * alamat, dan pekerjaan tidak bisa dikosongkan, NIK bisa (`null`).
 */
class LengkapiProfilWaliRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'no_hp' => ['sometimes', 'required', new NomorHp],
            'alamat' => ['sometimes', 'required', 'string', 'max:500'],
            'pekerjaan' => ['sometimes', 'required', 'string', 'max:100'],
            'nik' => ['sometimes', 'nullable', 'digits:16'],
        ];
    }

    /**
     * @return array{no_hp?: string, alamat?: string, pekerjaan?: string, nik?: string|null}
     */
    public function dataWali(): array
    {
        /** @var array{no_hp?: string, alamat?: string, pekerjaan?: string, nik?: string|null} */
        return $this->validated();
    }
}
