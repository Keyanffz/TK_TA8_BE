<?php

namespace App\Http\Requests\Absensi;

use App\Enums\JenisAbsensi;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * `POST /absensi` (multipart). Di sini hanya bentuk isian; hari kerja, jam, jarak, dan akurasi diperiksa
 * `AbsensiService` dengan jam server.
 */
class AbsenRequest extends FormRequest
{
    /** Foto swafoto sudah dikompres di FE, jadi batasnya lebih kecil dari unggahan lain (B5). */
    public const UKURAN_MAKSIMAL_FOTO_KB = 2 * 1024;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'jenis' => ['required', Rule::enum(JenisAbsensi::class)],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            /** Akurasi lokasi dari perangkat, dalam meter. */
            'akurasi' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'foto' => ['required', 'image', 'mimes:'.implode(',', MediaService::EKSTENSI_GAMBAR), 'max:'.self::UKURAN_MAKSIMAL_FOTO_KB],
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
            'latitude.required' => 'Lokasi belum terbaca. Izinkan akses lokasi lalu coba lagi.',
            'longitude.required' => 'Lokasi belum terbaca. Izinkan akses lokasi lalu coba lagi.',
        ];
    }

    public function jenis(): JenisAbsensi
    {
        return $this->enum('jenis', JenisAbsensi::class) ?? throw new LogicException('Jenis absensi sudah divalidasi wajib ada.');
    }

    public function foto(): UploadedFile
    {
        $file = $this->file('foto');

        return $file instanceof UploadedFile ? $file : throw new LogicException('Foto sudah divalidasi wajib ada.');
    }
}
