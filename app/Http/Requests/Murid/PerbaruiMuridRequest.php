<?php

namespace App\Http\Requests\Murid;

use App\Enums\StatusMurid;
use Illuminate\Validation\Rule;

/**
 * `PUT /murid/{id}` (multipart jika menyertakan foto): data yang sama dengan `POST /murid` ditambah `status`
 * (wajib) dan `tanggal_keluar` (wajib untuk murid yang lulus, pindah, atau keluar; kosong untuk murid aktif).
 */
class PerbaruiMuridRequest extends SimpanMuridRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['required', Rule::enum(StatusMurid::class)],
            'tanggal_keluar' => [
                'nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal_masuk',
                'required_unless:status,'.StatusMurid::Aktif->value, 'prohibited_if:status,'.StatusMurid::Aktif->value,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tanggal_keluar.required_unless' => 'Tanggal keluar wajib diisi untuk murid yang lulus, pindah, atau keluar.',
            'tanggal_keluar.prohibited_if' => 'Murid aktif tidak punya tanggal keluar.',
        ];
    }
}
