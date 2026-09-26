<?php

namespace App\Http\Requests\Rapor;

use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use LogicException;

class FotoRaporRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'foto' => ['required', ...MediaService::aturanGambar()],
        ];
    }

    public function foto(): UploadedFile
    {
        $file = $this->file('foto');

        return $file instanceof UploadedFile ? $file : throw new LogicException('Foto sudah divalidasi wajib ada.');
    }
}
