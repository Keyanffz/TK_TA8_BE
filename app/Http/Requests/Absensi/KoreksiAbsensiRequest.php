<?php

namespace App\Http\Requests\Absensi;

use App\Enums\StatusAbsensi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class KoreksiAbsensiRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(StatusAbsensi::class)],
            'catatan' => ['required', 'string', 'max:500'],
        ];
    }

    public function status(): StatusAbsensi
    {
        return $this->enum('status', StatusAbsensi::class) ?? throw new LogicException('Status sudah divalidasi wajib ada.');
    }

    public function catatan(): string
    {
        return $this->string('catatan')->trim()->toString();
    }
}
