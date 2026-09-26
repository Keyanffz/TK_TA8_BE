<?php

namespace App\Http\Requests\Galeri;

use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * `POST /galeri-album` dan `PUT /galeri-album/{id}`; `cover` opsional (multipart). Tanpa sampul, foto pertama
 * album dipakai sebagai sampul.
 */
class SimpanAlbumRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'judul' => ['required', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string', 'max:2000'],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'is_publik' => ['sometimes', 'boolean'],
            'cover' => ['nullable', ...MediaService::aturanGambar()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dataAlbum(): array
    {
        return collect($this->validated())->except('cover')->all();
    }

    public function cover(): ?UploadedFile
    {
        $file = $this->file('cover');

        return $file instanceof UploadedFile ? $file : null;
    }
}
