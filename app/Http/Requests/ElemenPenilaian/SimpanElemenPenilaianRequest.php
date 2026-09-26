<?php

namespace App\Http\Requests\ElemenPenilaian;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kode elemen berupa huruf besar, angka, dan garis bawah (misal `NAB`, `LITERASI_STEAM`), unik.
 */
class SimpanElemenPenilaianRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('kode'))) {
            $this->merge(['kode' => strtoupper(trim($this->input('kode')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kode' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_]+$/', Rule::unique('elemen_penilaian', 'kode')->ignore($this->route('id'))],
            'nama' => ['required', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string', 'max:2000'],
            'urutan' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_aktif' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['kode' => 'kode elemen'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['kode.regex' => 'Kode elemen hanya boleh berisi huruf, angka, dan garis bawah.'];
    }
}
