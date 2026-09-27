<?php

namespace App\Http\Requests\Murid;

use App\Enums\Hubungan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /murid/{id}/wali/{wali_murid_id}`: salah satu atau keduanya; field yang tidak dikirim tidak berubah.
 */
class UbahTautanWaliRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hubungan' => ['required_without:is_kontak_utama', Rule::enum(Hubungan::class)],
            'is_kontak_utama' => ['required_without:hubungan', 'boolean'],
        ];
    }

    public function hubungan(): ?Hubungan
    {
        return $this->has('hubungan') ? Hubungan::from($this->string('hubungan')->toString()) : null;
    }

    public function kontakUtama(): ?bool
    {
        return $this->has('is_kontak_utama') ? $this->boolean('is_kontak_utama') : null;
    }
}
