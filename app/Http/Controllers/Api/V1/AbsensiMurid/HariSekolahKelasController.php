<?php

namespace App\Http\Controllers\Api\V1\AbsensiMurid;

use App\Http\Controllers\Controller;
use App\Http\Requests\AbsensiMurid\TambahLiburKelasRequest;
use App\Http\Requests\AbsensiMurid\UbahHariSekolahRequest;
use App\Models\Kelas;
use App\Models\User;
use App\Services\HariSekolahKelasService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * Hari sekolah dan tanggal libur absensi murid satu kelas. Dilihat Kepala Sekolah, wali kelas, dan guru pendamping
 * kelas itu di tahun ajaran aktif (guru lain 404, wali murid 403); diubah Kepala Sekolah dan wali kelas (guru
 * pendamping 403). Semua aksi membalas bentuk yang sama dengan `GET`.
 */
class HariSekolahKelasController extends Controller
{
    public function __construct(private readonly HariSekolahKelasService $hariSekolah) {}

    /**
     * `hari_sekolah` nomor hari ISO (1 = Senin). `libur` urut tanggal mulai; libur satu hari punya tanggal mulai dan
     * selesai yang sama. `bisa_diatur` true untuk Kepala Sekolah dan wali kelas.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{hari_sekolah: list<int>, libur: list<array{id: int, tanggal_mulai: string, tanggal_selesai: string, keterangan: string}>, bisa_diatur: bool},
     *     meta: null
     * }
     */
    public function show(int $id, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::success($this->hariSekolah->data(Kelas::untukPengampu($id, $user), $user));
    }

    /**
     * Mengganti hari sekolah mingguan. Absensi yang sudah tercatat di hari yang dilepas tetap tersimpan tetapi tidak
     * dihitung di rekap.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{hari_sekolah: list<int>, libur: list<array{id: int, tanggal_mulai: string, tanggal_selesai: string, keterangan: string}>, bisa_diatur: bool},
     *     meta: null
     * }
     */
    public function ubahHari(UbahHariSekolahRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $kelas = Kelas::untukPengampu($id, $user);
        $this->hariSekolah->pastikanBolehAtur($kelas, $user);
        $this->hariSekolah->ubahHari($kelas, $request->hari());

        return ApiResponse::success($this->hariSekolah->data($kelas, $user), "Hari sekolah {$kelas->nama} disimpan.");
    }

    /**
     * Menambah libur satu tanggal atau rentang di dalam tahun ajaran kelas. Ditolak 422 `BUSINESS_RULE` kalau di
     * luar tahun ajaran atau bertumpuk dengan libur lain. Absensi yang sudah tercatat di rentang itu tidak dihapus.
     */
    #[Response(201, 'Libur ditambahkan', type: 'array{success: true, message: string, data: array{hari_sekolah: list<int>, libur: list<array{id: int, tanggal_mulai: string, tanggal_selesai: string, keterangan: string}>, bisa_diatur: bool}, meta: null}')]
    public function tambahLibur(TambahLiburKelasRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $kelas = Kelas::untukPengampu($id, $user);
        $this->hariSekolah->pastikanBolehAtur($kelas, $user);
        $libur = $this->hariSekolah->tambahLibur($kelas, $request->mulai(), $request->selesai(), $request->keterangan());

        $pesan = "Libur {$this->hariSekolah->rentang($libur->tanggal_mulai, $libur->tanggal_selesai)} ditambahkan.";
        if ($this->hariSekolah->adaAbsensiTercatat($libur)) {
            $pesan .= ' Absensi murid yang sudah tercatat pada tanggal itu tetap tersimpan, tetapi tidak dihitung di rekap.';
        }

        return ApiResponse::success($this->hariSekolah->data($kelas, $user), $pesan, status: 201);
    }

    /**
     * Libur kelas lain → 404.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{hari_sekolah: list<int>, libur: list<array{id: int, tanggal_mulai: string, tanggal_selesai: string, keterangan: string}>, bisa_diatur: bool},
     *     meta: null
     * }
     */
    public function hapusLibur(int $id, int $libur_id, #[CurrentUser] User $user): JsonResponse
    {
        $kelas = Kelas::untukPengampu($id, $user);
        $this->hariSekolah->pastikanBolehAtur($kelas, $user);
        $libur = $this->hariSekolah->hapusLibur($kelas, $libur_id);

        return ApiResponse::success($this->hariSekolah->data($kelas, $user), "Libur {$this->hariSekolah->rentang($libur->tanggal_mulai, $libur->tanggal_selesai)} dihapus.");
    }
}
