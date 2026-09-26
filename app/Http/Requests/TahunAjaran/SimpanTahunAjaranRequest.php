<?php

namespace App\Http\Requests\TahunAjaran;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dipakai `POST /tahun-ajaran` dan `PUT /tahun-ajaran/{id}`. Status aktif tidak diubah lewat sini,
 * melainkan lewat `POST /tahun-ajaran/{id}/aktifkan`.
 */
class SimpanTahunAjaranRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nama' => [
                'required', 'string', 'regex:/^\d{4}\/\d{4}$/', $this->tahunBerurutan(),
                Rule::unique('tahun_ajaran', 'nama')->ignore($this->route('id')),
            ],
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['required', 'date_format:Y-m-d', 'after:tanggal_mulai'],
            'semester_aktif' => ['sometimes', 'integer', Rule::in([1, 2])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.regex' => 'Nama tahun ajaran ditulis dengan format 2026/2027.',
        ];
    }

    private function tahunBerurutan(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            [$awal, $akhir] = array_map('intval', explode('/', (string) $value) + [1 => 0]);

            if ($akhir !== $awal + 1) {
                $fail('Tahun kedua pada nama tahun ajaran harus satu tahun setelah tahun pertama, misalnya 2026/2027.');
            }
        };
    }
}
