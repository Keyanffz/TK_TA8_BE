<?php

namespace App\Http\Requests\Rapor;

use App\Models\Murid;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guru hanya membuat rapor untuk murid di kelas yang dia ampu; Kepala Sekolah untuk murid mana pun.
 */
class BuatRaporRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'murid_id' => ['required', 'integer', $this->muridTerlihat(...)],
            'semester' => ['required', 'integer', 'in:1,2'],
        ];
    }

    public function murid(): Murid
    {
        return Murid::query()->findOrFail($this->integer('murid_id'));
    }

    private function muridTerlihat(string $attribute, mixed $value, Closure $fail): void
    {
        $user = $this->user();

        if (! $user instanceof User || ! Murid::query()->visibleTo($user)->whereKey($value)->exists()) {
            $fail('Murid tidak ditemukan di kelas yang Anda ampu.');
        }
    }
}
