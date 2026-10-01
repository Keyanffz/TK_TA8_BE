<?php

namespace App\Http\Requests\Absensi;

use Illuminate\Validation\Rule;

class RiwayatAbsensiRequest extends BulanAbsensiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** Peserta yang riwayatnya dibuka Kepala Sekolah; bawaan diri sendiri. */
            'user_id' => ['sometimes', 'integer', Rule::exists('users', 'id')],
        ];
    }

    public function userId(): ?int
    {
        return $this->filled('user_id') ? $this->integer('user_id') : null;
    }
}
