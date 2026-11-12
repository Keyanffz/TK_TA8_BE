<?php

namespace App\Http\Requests\Absensi;

use App\Enums\StatusAbsensi;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;

class KoreksiAbsensiRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(StatusAbsensi::class)],
            'catatan' => ['required', 'string', 'max:500'],
            /** Opsional: foto atau dokumen pendukung (JPG, PNG, WebP, atau PDF, maks 5 MB). Body multipart kalau dikirim. */
            'lampiran' => ['nullable', ...MediaService::aturanDokumen()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lampiran.mimes' => 'Lampiran harus berupa PDF atau gambar (JPG, PNG, WebP).',
            'lampiran.max' => 'Ukuran lampiran maksimal 5 MB.',
        ];
    }

    public function status(): StatusAbsensi
    {
        return $this->enum('status', StatusAbsensi::class) ?? throw new LogicException('Status sudah divalidasi wajib ada.');
    }

    public function catatan(): string
    {
        return $this->string('catatan')->trim()->toString();
    }

    public function lampiran(): ?UploadedFile
    {
        $file = $this->file('lampiran');

        return $file instanceof UploadedFile ? $file : null;
    }
}
