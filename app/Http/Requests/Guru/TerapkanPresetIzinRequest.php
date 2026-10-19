<?php

namespace App\Http\Requests\Guru;

use App\Enums\PresetIzin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class TerapkanPresetIzinRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preset' => ['required', Rule::enum(PresetIzin::class)],
        ];
    }

    public function preset(): PresetIzin
    {
        return $this->enum('preset', PresetIzin::class) ?? throw new LogicException('Preset sudah divalidasi wajib ada.');
    }
}
