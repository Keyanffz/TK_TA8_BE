<?php

namespace App\Http\Requests\Murid;

use App\Services\NisMuridService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /murid/{id}/nis`. Format, keunikan, dan aturan murid diperiksa `NisMuridService` (pesan di field `nis`).
 */
class IsiNisMuridRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Huruf, angka, titik, strip, dan garis miring, maksimal 20 karakter; disimpan huruf besar tanpa spasi. */
            'nis' => ['required', 'string', 'max:50'],
        ];
    }

    public function nis(): string
    {
        return NisMuridService::normalkan($this->string('nis')->toString());
    }
}
