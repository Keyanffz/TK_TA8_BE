<?php

namespace App\Http\Requests\Murid;

use App\Enums\JenisKelamin;
use App\Enums\StatusMurid;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Dipakai `POST /murid` dan `PUT /murid/{id}` (multipart jika menyertakan foto). NIS dibuat otomatis
 * saat murid dibuat dan tidak bisa diubah. Status dan tanggal keluar hanya diisi saat memperbarui;
 * murid baru selalu berstatus aktif.
 */
class SimpanMuridRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $memperbarui = $this->route('id') !== null;

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
            'status' => $memperbarui ? ['required', Rule::enum(StatusMurid::class)] : ['prohibited'],
            'tanggal_keluar' => $memperbarui
                ? ['nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal_masuk', 'required_unless:status,'.StatusMurid::Aktif->value, 'prohibited_if:status,'.StatusMurid::Aktif->value]
                : ['prohibited'],
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
