<?php

namespace App\Http\Requests\Absensi;

use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * `POST /absensi/susulan/{id}/absen`: hanya swafoto; lokasi dan jam tidak diperiksa.
 */
class AbsenSusulanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Swafoto, jpeg, png, atau webp, maksimal 2 MB. */
            'foto' => ['required', 'image', 'mimes:'.implode(',', MediaService::EKSTENSI_GAMBAR), 'max:'.AbsenRequest::UKURAN_MAKSIMAL_FOTO_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'foto.max' => 'Ukuran foto paling besar 2 MB.',
            'foto.required' => 'Foto wajib diambil sebelum absen.',
        ];
    }

    public function foto(): UploadedFile
    {
        $file = $this->file('foto');

        return $file instanceof UploadedFile ? $file : throw new LogicException('Foto sudah divalidasi wajib ada.');
    }
}
