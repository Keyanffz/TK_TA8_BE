<?php

namespace App\Http\Requests\Guru;

use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /guru/{id}/izin`: kelima modul wajib dikirim, `null` berarti guru tidak punya izin modul itu. Isian ini
 * mengganti seluruh izin guru.
 */
class SimpanIzinGuruRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $level = ['present', 'nullable', Rule::enum(LevelIzin::class)];

        return [
            'izin' => ['required', 'array:'.implode(',', array_column(ModulIzin::cases(), 'value'))],
            'izin.keuangan' => $level,
            'izin.konten_website' => $level,
            'izin.laporan_absensi' => $level,
            'izin.data_murid' => $level,
            'izin.pengumuman' => $level,
        ];
    }

    /**
     * @return array<string, LevelIzin|null>
     */
    public function izin(): array
    {
        $izin = [];

        foreach (ModulIzin::cases() as $modul) {
            $nilai = $this->validated("izin.{$modul->value}");
            $izin[$modul->value] = is_string($nilai) ? LevelIzin::from($nilai) : null;
        }

        return $izin;
    }
}
