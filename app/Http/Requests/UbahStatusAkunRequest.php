<?php

namespace App\Http\Requests;

use App\Enums\StatusAkun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /guru/{id}/status` dan `PATCH /wali-murid/{id}/status`: hanya aktif ↔ nonaktif.
 */
class UbahStatusAkunRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(StatusAkun::class)->only([StatusAkun::Aktif, StatusAkun::Nonaktif])],
        ];
    }

    public function status(): StatusAkun
    {
        return StatusAkun::from($this->string('status')->toString());
    }
}
