<?php

namespace App\Http\Requests\Auth;

use App\Rules\NomorHp;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class PerbaruiProfilRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'no_hp' => ['nullable', new NomorHp],
            'avatar' => ['nullable', ...MediaService::aturanGambar()],
        ];
    }

    public function avatar(): ?UploadedFile
    {
        $file = $this->file('avatar');

        return $file instanceof UploadedFile ? $file : null;
    }
}
