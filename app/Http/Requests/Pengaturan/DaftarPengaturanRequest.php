<?php

namespace App\Http\Requests\Pengaturan;

use Illuminate\Foundation\Http\FormRequest;

class DaftarPengaturanRequest extends FormRequest
{
    public const GRUP = ['profil', 'landing', 'keuangan', 'ppdb'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'grup' => ['sometimes', 'string', 'in:'.implode(',', self::GRUP)],
        ];
    }

    public function grup(): ?string
    {
        return $this->filled('grup') ? $this->string('grup')->toString() : null;
    }
}
