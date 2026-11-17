<?php

namespace App\Http\Requests\Pendaftaran;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /pendaftaran/{id}/mengundurkan-diri`: alasan opsional, disimpan di `catatan`.
 */
class MundurPendaftaranRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'alasan' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function alasan(): ?string
    {
        $alasan = $this->string('alasan')->trim()->toString();

        return $alasan === '' ? null : $alasan;
    }
}
