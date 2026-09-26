<?php

namespace App\Http\Requests\Guru;

use App\Enums\JenisKelamin;
use App\Models\Guru;
use App\Rules\NomorHp;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Dipakai `POST /guru` dan `PUT /guru/{id}`; saat memperbarui, email boleh sama dengan milik guru itu sendiri.
 */
class SimpanGuruRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->route('id') === null ? null : Guru::query()->whereKey($this->route('id'))->value('user_id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'no_hp' => ['required', new NomorHp],
            'jenis_kelamin' => ['required', Rule::enum(JenisKelamin::class)],
            'nip' => ['nullable', 'string', 'max:30'],
            'nuptk' => ['nullable', 'digits:16'],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            'tanggal_lahir' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'pendidikan_terakhir' => ['nullable', 'string', 'max:100'],
            'jabatan' => ['sometimes', 'string', 'max:100'],
            'bisa_kelola_keuangan' => ['sometimes', 'boolean'],
            'tampil_di_landing' => ['sometimes', 'boolean'],
            'foto' => ['nullable', ...MediaService::aturanGambar()],
        ];
    }

    public function foto(): ?UploadedFile
    {
        $file = $this->file('foto');

        return $file instanceof UploadedFile ? $file : null;
    }
}
