<?php

namespace App\Http\Requests\AbsensiMurid;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class TambahLiburKelasRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** `YYYY-MM-DD`. */
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
            /** `YYYY-MM-DD`, kosong untuk libur satu hari. */
            'tanggal_selesai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
            /** Misalnya "Libur semester ganjil". */
            'keterangan' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
        ];
    }

    public function mulai(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('tanggal_mulai')->toString())->startOfDay();
    }

    public function selesai(): Carbon
    {
        return $this->filled('tanggal_selesai')
            ? Carbon::createFromFormat('Y-m-d', $this->string('tanggal_selesai')->toString())->startOfDay()
            : $this->mulai();
    }

    public function keterangan(): string
    {
        return rtrim($this->string('keterangan')->trim()->toString(), '.');
    }
}
