<?php

namespace App\Http\Requests\Rapor;

use App\Models\Murid;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guru dan Kepala Sekolah hanya membuat rapor untuk murid di kelas yang dia ampu (wali kelas atau guru
 * pendamping) di tahun ajaran aktif.
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

        $diKelasnya = $user instanceof User && Murid::query()->whereKey($value)
            ->whereHas('kelas', fn (Builder $kelas) => $kelas->diampuOleh($user))->exists();

        if (! $diKelasnya) {
            $fail('Murid tidak ditemukan di kelas yang Anda ampu.');
        }
    }
}
