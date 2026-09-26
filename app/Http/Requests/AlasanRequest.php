<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body `{ alasan }` untuk aksi penolakan dan pembatalan: tolak guru, batalkan tagihan, tolak pembayaran.
 */
class AlasanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'alasan' => ['required', 'string', 'max:500'],
        ];
    }

    public function alasan(): string
    {
        return $this->string('alasan')->trim()->toString();
    }
}
