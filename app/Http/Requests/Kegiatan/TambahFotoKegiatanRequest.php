<?php

namespace App\Http\Requests\Kegiatan;

use App\Services\KegiatanKelasService;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class TambahFotoKegiatanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'foto' => ['required', 'array', 'min:1', 'max:'.KegiatanKelasService::MAKSIMAL_FOTO_PER_UNGGAHAN],
            'foto.*' => ['required', ...MediaService::aturanGambar()],
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function foto(): array
    {
        return array_values(array_filter((array) $this->file('foto', []), fn (mixed $file): bool => $file instanceof UploadedFile));
    }
}
