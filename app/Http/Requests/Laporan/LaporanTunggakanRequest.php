<?php

namespace App\Http\Requests\Laporan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LaporanTunggakanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kelas_id' => ['sometimes', 'nullable', 'integer', Rule::exists('kelas', 'id')],
        ];
    }

    public function kelasId(): ?int
    {
        return $this->filled('kelas_id') ? $this->integer('kelas_id') : null;
    }
}
