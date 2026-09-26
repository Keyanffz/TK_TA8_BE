<?php

namespace App\Http\Requests\Pengaturan;

use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use LogicException;

class UnggahGambarPengaturanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gambar' => ['required', ...MediaService::aturanGambar()],
        ];
    }

    public function gambar(): UploadedFile
    {
        $file = $this->file('gambar');

        return $file instanceof UploadedFile ? $file : throw new LogicException('Gambar sudah divalidasi wajib ada.');
    }
}
