<?php

namespace App\Http\Requests\Pendaftaran;

use App\Models\Kelas;
use Illuminate\Foundation\Http\FormRequest;

class TerimaPendaftaranRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kelas_id' => ['nullable', 'integer', 'exists:kelas,id'],
        ];
    }

    public function kelas(): ?Kelas
    {
        return $this->filled('kelas_id') ? Kelas::query()->findOrFail($this->integer('kelas_id')) : null;
    }
}
