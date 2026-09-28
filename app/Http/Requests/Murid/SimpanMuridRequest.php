<?php

namespace App\Http\Requests\Murid;

use App\Enums\JenisKelamin;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * `POST /murid` (multipart jika menyertakan foto). NIS dibuat otomatis saat murid dibuat dan tidak bisa
 * diubah. Murid baru selalu berstatus aktif, jadi `status` dan `tanggal_keluar` ditolak; keduanya diisi
 * lewat `PUT /murid/{id}` (`PerbaruiMuridRequest`).
 */
class SimpanMuridRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nisn' => ['nullable', 'digits:10', Rule::unique('murid', 'nisn')->ignore($this->route('id'))],
            'nik' => ['nullable', 'digits:16'],
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'nama_panggilan' => ['required', 'string', 'max:50'],
            'jenis_kelamin' => ['required', Rule::enum(JenisKelamin::class)],
            'tempat_lahir' => ['required', 'string', 'max:100'],
            'tanggal_lahir' => ['required', 'date_format:Y-m-d', 'before:today'],
            'agama' => ['required', 'string', 'max:20'],
            'alamat' => ['required', 'string', 'max:500'],
            'anak_ke' => ['nullable', 'integer', 'min:1', 'max:20'],
            'catatan_khusus' => ['nullable', 'string', 'max:1000'],
            'tanggal_masuk' => ['required', 'date_format:Y-m-d'],
            'foto' => ['nullable', ...MediaService::aturanGambar()],
            /** @ignoreParam */
            'status' => ['prohibited'],
            /** @ignoreParam */
            'tanggal_keluar' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dataMurid(): array
    {
        return collect($this->validated())->except('foto')->all();
    }

    public function foto(): ?UploadedFile
    {
        $file = $this->file('foto');

        return $file instanceof UploadedFile ? $file : null;
    }
}
