<?php

namespace App\Http\Requests\Agenda;

use App\Enums\JenisAgenda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanAgendaRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'judul' => ['required', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string', 'max:2000'],
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
            'jenis' => ['required', Rule::enum(JenisAgenda::class)],
            'is_publik' => ['sometimes', 'boolean'],
        ];
    }
}
