<?php

namespace App\Http\Requests\Pendaftaran;

use App\Enums\Hubungan;
use App\Enums\JenisKelamin;
use App\Enums\Tingkat;
use App\Rules\NomorHp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /pendaftaran` dan `POST /public/pendaftaran`. Tanpa unggahan; berkas dibawa pendaftar ke sekolah.
 */
class BuatPendaftaranRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hubungan' => ['required', Rule::enum(Hubungan::class)],
            'tingkat_tujuan' => ['required', Rule::enum(Tingkat::class)],
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'nama_panggilan' => ['required', 'string', 'max:50'],
            'jenis_kelamin' => ['required', Rule::enum(JenisKelamin::class)],
            'tempat_lahir' => ['required', 'string', 'max:100'],
            'tanggal_lahir' => ['required', 'date_format:Y-m-d', 'before:today'],
            'nik' => ['required', 'digits:16'],
            'agama' => ['required', 'string', 'max:20'],
            'alamat' => ['required', 'string', 'max:500'],
            'nama_ayah' => ['nullable', 'string', 'max:255'],
            'pekerjaan_ayah' => ['nullable', 'string', 'max:255'],
            'nama_ibu' => ['nullable', 'string', 'max:255'],
            'pekerjaan_ibu' => ['nullable', 'string', 'max:255'],
            'no_hp' => ['required', 'string', new NomorHp],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tingkat_tujuan' => 'kelompok tujuan',
        ];
    }
}
