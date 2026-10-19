<?php

namespace App\Http\Controllers\Api\V1\Guru;

use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use App\Enums\PresetIzin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guru\SimpanIzinGuruRequest;
use App\Http\Requests\Guru\TerapkanPresetIzinRequest;
use App\Http\Resources\GuruResource;
use App\Models\Guru;
use App\Models\User;
use App\Services\IzinGuruService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * Hak akses guru (A2.13). Daftar guru beserta izinnya diambil lewat `GET /guru` (field `izin`).
 */
class IzinGuruController extends Controller
{
    public function __construct(private readonly IzinGuruService $izinGuru) {}

    /**
     * Modul, level, dan preset izin yang bisa diberikan ke guru. `kelola` selalu mencakup `lihat`. Manajemen guru,
     * manajemen izin, dan pengaturan absensi tidak termasuk karena tetap khusus Kepala Sekolah.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         modul: list<array{kode: ModulIzin, label: string, deskripsi: string}>,
     *         level: list<array{kode: LevelIzin, label: string}>,
     *         preset: list<array{
     *             kode: PresetIzin,
     *             nama: string,
     *             izin: array{keuangan: LevelIzin|null, konten_website: LevelIzin|null, laporan_absensi: LevelIzin|null, data_murid: LevelIzin|null, pengumuman: LevelIzin|null}
     *         }>
     *     },
     *     meta: null
     * }
     */
    public function index(): JsonResponse
    {
        $tanpaIzin = array_fill_keys(array_column(ModulIzin::cases(), 'value'), null);

        return ApiResponse::success([
            'modul' => array_map(fn (ModulIzin $modul): array => [
                'kode' => $modul,
                'label' => $modul->label(),
                'deskripsi' => $modul->deskripsi(),
            ], ModulIzin::cases()),
            'level' => array_map(fn (LevelIzin $level): array => ['kode' => $level, 'label' => $level->label()], LevelIzin::cases()),
            'preset' => array_map(fn (PresetIzin $preset): array => [
                'kode' => $preset,
                'nama' => $preset->label(),
                'izin' => [...$tanpaIzin, ...$preset->izin()],
            ], PresetIzin::cases()),
        ]);
    }

    /**
     * Mengganti seluruh izin satu guru. Berlaku di request guru berikutnya tanpa perlu masuk ulang. Ditolak 422
     * `BUSINESS_RULE` untuk profil guru milik Kepala Sekolah. Dicatat di log aktivitas `izin` (event `diubah`).
     */
    public function update(SimpanIzinGuruRequest $request, int $id, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $guru = $this->izinGuru->atur($this->cariGuru($id), $request->izin(), $kepalaSekolah);

        return ApiResponse::success(new GuruResource($guru), "Izin {$guru->user->name} tersimpan.");
    }

    /**
     * Mengganti seluruh izin guru dengan isi preset; setelah itu izin masih bisa disesuaikan lewat
     * `PUT /guru/{id}/izin`. Dicatat di log aktivitas `izin` (event `preset_diterapkan`).
     */
    public function preset(TerapkanPresetIzinRequest $request, int $id, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $preset = $request->preset();
        $guru = $this->izinGuru->terapkanPreset($this->cariGuru($id), $preset, $kepalaSekolah);

        return ApiResponse::success(new GuruResource($guru), "Preset {$preset->label()} diterapkan untuk {$guru->user->name}.");
    }

    private function cariGuru(int $id): Guru
    {
        return Guru::query()->with(['user', 'izin'])->findOrFail($id);
    }
}
