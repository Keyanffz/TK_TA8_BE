<?php

namespace App\Http\Requests\Wali;

use App\Support\FormulirMurid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /wali/anak/{id}`: isian formulir yang masih kosong. Isian yang sudah terisi tidak bisa diubah wali; nilai
 * lain untuk isian itu ditolak di `MuridService::lengkapi()`.
 */
class LengkapiDataAnakRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nik' => ['nullable', 'digits:16', Rule::unique('murid', 'nik')->ignore($this->route('id'))],
            'anak_ke' => ['nullable', 'integer', 'min:1', 'max:20'],
            ...FormulirMurid::aturanAnak(),
            ...FormulirMurid::aturanOrangTua(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return FormulirMurid::atribut();
    }
}
