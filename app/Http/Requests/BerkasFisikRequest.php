<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /pendaftaran/{id}/berkas` dan `PATCH /murid/{id}/berkas`: `true` mencentang berkas yang sudah diterima
 * sekolah, `false` menghapus centangnya. Minimal satu berkas dikirim.
 */
class BerkasFisikRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kartu_keluarga' => ['required_without:akta_kelahiran', 'boolean'],
            'akta_kelahiran' => ['required_without:kartu_keluarga', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['kartu_keluarga' => 'Kartu Keluarga', 'akta_kelahiran' => 'akta kelahiran'];
    }

    /**
     * @return array<string, bool>
     */
    public function centang(): array
    {
        return array_map(fn (mixed $nilai): bool => filter_var($nilai, FILTER_VALIDATE_BOOLEAN), $this->validated());
    }
}
