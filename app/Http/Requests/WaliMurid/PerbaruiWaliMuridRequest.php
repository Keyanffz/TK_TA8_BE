<?php

namespace App\Http\Requests\WaliMurid;

use App\Rules\NomorHp;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /wali-murid/{id}` oleh Kepala Sekolah, misalnya membetulkan nama atau nomor HP yang salah ketik.
 * Field yang tidak dikirim tidak berubah. Akun wali tidak punya email; username (NIS anak) tidak bisa diubah.
 */
class PerbaruiWaliMuridRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nama' => ['sometimes', 'required', 'string', 'max:255'],
            'no_hp' => ['sometimes', 'required', new NomorHp],
            'nik' => ['sometimes', 'nullable', 'digits:16'],
            'alamat' => ['sometimes', 'required', 'string', 'max:500'],
            'pekerjaan' => ['sometimes', 'required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array{nama?: string, no_hp?: string, alamat?: string, pekerjaan?: string, nik?: string|null}
     */
    public function dataWali(): array
    {
        /** @var array{nama?: string, no_hp?: string, alamat?: string, pekerjaan?: string, nik?: string|null} */
        return $this->validated();
    }
}
