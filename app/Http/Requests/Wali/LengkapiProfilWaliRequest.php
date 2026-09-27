<?php

namespace App\Http\Requests\Wali;

use App\Rules\NomorHp;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /wali/profil`: onboarding setelah ganti password awal, sekaligus ubah profil. Nama (menggantikan nama
 * sementara "Wali …") dan nomor HP selalu wajib. NIK, alamat, dan pekerjaan opsional: yang tidak dikirim tidak
 * berubah, yang dikirim kosong (`null`) dikosongkan.
 */
class LengkapiProfilWaliRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:255'],
            'no_hp' => ['required', new NomorHp],
            'alamat' => ['sometimes', 'nullable', 'string', 'max:500'],
            'pekerjaan' => ['sometimes', 'nullable', 'string', 'max:100'],
            'nik' => ['sometimes', 'nullable', 'digits:16'],
        ];
    }

    /**
     * @return array{nama: string, no_hp: string, alamat?: string|null, pekerjaan?: string|null, nik?: string|null}
     */
    public function dataWali(): array
    {
        /** @var array{nama: string, no_hp: string, alamat?: string|null, pekerjaan?: string|null, nik?: string|null} */
        return $this->validated();
    }
}
