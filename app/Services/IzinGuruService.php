<?php

namespace App\Services;

use App\Enums\LevelIzin;
use App\Enums\PresetIzin;
use App\Exceptions\BusinessRuleException;
use App\Models\Guru;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Izin modul guru dari Kepala Sekolah (A2.13). Setiap perubahan mengganti seluruh izin guru itu dan dicatat di log
 * aktivitas `izin` dengan isi sebelum dan sesudahnya.
 */
class IzinGuruService
{
    /**
     * @param  array<string, LevelIzin|null>  $izin  level per nilai `ModulIzin`; modul yang tidak disebut dicabut
     *
     * @throws BusinessRuleException
     */
    public function atur(Guru $guru, array $izin, User $kepalaSekolah, ?PresetIzin $preset = null): Guru
    {
        if ($guru->milikKepalaSekolah()) {
            throw new BusinessRuleException('Izin profil Kepala Sekolah tidak bisa diubah karena Kepala Sekolah selalu punya semua izin.');
        }

        $sebelum = $this->ringkas($guru->izinPerModul());
        $sesudah = $this->ringkas([...array_fill_keys(array_keys($guru->izinPerModul()), null), ...$izin]);

        DB::transaction(function () use ($guru, $sesudah): void {
            $guru->izin()->delete();

            foreach (array_filter($sesudah) as $modul => $level) {
                $guru->izin()->create(['modul' => $modul, 'level' => $level]);
            }
        });

        if ($sebelum !== $sesudah) {
            activity('izin')->causedBy($kepalaSekolah)->performedOn($guru)
                ->event($preset === null ? 'diubah' : 'preset_diterapkan')
                ->withProperties(['sebelum' => $sebelum, 'sesudah' => $sesudah, 'preset' => $preset?->value])
                ->log($preset === null
                    ? "Mengubah izin {$guru->user->name}"
                    : "Menerapkan preset {$preset->label()} untuk {$guru->user->name}");
        }

        return $guru->load('izin');
    }

    /**
     * @throws BusinessRuleException
     */
    public function terapkanPreset(Guru $guru, PresetIzin $preset, User $kepalaSekolah): Guru
    {
        return $this->atur($guru, $preset->izin(), $kepalaSekolah, $preset);
    }

    /**
     * @param  array<string, LevelIzin|null>  $izin
     * @return array<string, string|null>
     */
    private function ringkas(array $izin): array
    {
        return array_map(fn (?LevelIzin $level): ?string => $level?->value, $izin);
    }
}
