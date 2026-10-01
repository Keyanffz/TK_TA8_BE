<?php

namespace App\Http\Controllers\Api\V1\Absensi;

use App\Enums\JenisAbsensi;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsenRequest;
use App\Http\Requests\Absensi\BulanAbsensiRequest;
use App\Http\Requests\Absensi\KoreksiAbsensiRequest;
use App\Http\Requests\Absensi\RiwayatAbsensiRequest;
use App\Http\Resources\AbsensiResource;
use App\Models\Absensi;
use App\Models\User;
use App\Services\AbsensiService;
use App\Services\MediaService;
use App\Services\RekapAbsensiService;
use App\Support\ApiResponse;
use App\Support\Jangkauan;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AbsensiController extends Controller
{
    public function __construct(private readonly AbsensiService $absensiService) {}

    /**
     * Status absensi hari ini untuk pengguna yang login: apakah hari kerja, jam tiap jenis absen dan apakah
     * sedang terbuka menurut jam server, absensi yang sudah tercatat, serta lokasi dan radius sekolah untuk
     * menampilkan jarak di FE. `hari_kerja` false kalau hari itu di luar hari kerja atau termasuk tanggal libur.
     * Sebelum `tanggal_mulai`, `terbuka` selalu false.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         tanggal: string,
     *         waktu_server: string,
     *         hari_kerja: bool,
     *         tanggal_libur: bool,
     *         tanggal_mulai: string|null,
     *         lokasi: array{latitude: float, longitude: float}|null,
     *         radius_meter: int,
     *         batas_akurasi_meter: int,
     *         masuk: array{buka: string, batas_terlambat: string, tutup: string, terbuka: bool, absensi: AbsensiResource|null},
     *         pulang: array{buka: string, tutup: string, terbuka: bool, absensi: AbsensiResource|null}
     *     },
     *     meta: null
     * }
     */
    public function hariIni(#[CurrentUser] User $user): JsonResponse
    {
        $sekarang = now();
        $aturan = $this->absensiService->aturan();
        $tercatat = $this->absensiService->absensiTanggal($user, $sekarang);
        $libur = $aturan->tanggalLibur($sekarang);
        $hariKerja = $aturan->hariKerja($sekarang) && ! $libur;
        $berlaku = $hariKerja && $aturan->sudahMulai($sekarang);
        $jenis = fn (JenisAbsensi $jenis): array => [
            'terbuka' => $berlaku && $aturan->jendelaTerbuka($jenis, $sekarang),
            'absensi' => $tercatat->has($jenis->value) ? new AbsensiResource($tercatat->get($jenis->value)) : null,
        ];

        return ApiResponse::success([
            'tanggal' => $sekarang->toDateString(),
            'waktu_server' => $sekarang->toIso8601String(),
            'hari_kerja' => $hariKerja,
            'tanggal_libur' => $libur,
            'tanggal_mulai' => $aturan->tanggalMulai,
            'lokasi' => $aturan->lokasi,
            'radius_meter' => $aturan->radiusMeter,
            'batas_akurasi_meter' => $aturan->batasAkurasiMeter,
            'masuk' => [...$aturan->jamMasuk, ...$jenis(JenisAbsensi::Masuk)],
            'pulang' => [...$aturan->jamPulang, ...$jenis(JenisAbsensi::Pulang)],
        ]);
    }

    /**
     * Absen masuk atau pulang (`multipart/form-data`). Ditolak 422 `BUSINESS_RULE` dengan pesan yang
     * menjelaskan sebabnya: sebelum tanggal mulai absensi, bukan hari kerja, tanggal libur, di luar jam, sudah absen jenis itu hari ini, absen
     * pulang tanpa absen masuk, akurasi lokasi melebihi batas, atau di luar radius sekolah. Status absen masuk
     * `hadir` atau `terlambat` ditentukan dari jam server. Dibatasi 10 kali per menit per pengguna.
     */
    public function store(AbsenRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $absensi = $this->absensiService->absen(
            $user,
            $request->jenis(),
            $request->float('latitude'),
            $request->float('longitude'),
            $request->float('akurasi'),
            $request->foto(),
        );

        return ApiResponse::success(
            new AbsensiResource($absensi),
            "Absen {$absensi->jenis->value} tercatat pukul {$absensi->waktu?->format('H:i')}.",
            status: 201,
        );
    }

    /**
     * Riwayat absensi satu peserta dalam satu bulan, terbaru dulu, tanpa paginasi. Guru hanya melihat
     * riwayatnya sendiri; Kepala Sekolah bisa membuka riwayat peserta lain lewat `user_id`.
     */
    public function index(RiwayatAbsensiRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $userId = $request->userId() ?? $user->id;

        if ($user->role !== Role::SuperAdmin && $userId !== $user->id) {
            abort(403);
        }

        $bulan = $request->bulan();
        $absensi = Absensi::query()->with('pengoreksi')
            ->where('user_id', $userId)
            ->whereDate('tanggal', '>=', $bulan->toDateString())
            ->whereDate('tanggal', '<=', $bulan->copy()->endOfMonth()->toDateString())
            ->orderByDesc('tanggal')
            ->orderBy('jenis')
            ->get();

        return ApiResponse::success(AbsensiResource::collection($absensi));
    }

    /**
     * Foto absensi. Hanya pemilik absensi dan Kepala Sekolah; guru lain dibalas 404. Absensi tanpa foto
     * (tidak hadir, atau foto sudah melewati masa simpan) juga 404.
     */
    #[Response(200, 'Foto absensi (JPEG)', mediaType: 'image/jpeg', type: 'string', format: 'binary')]
    public function foto(int $id, MediaService $media): StreamedResponse
    {
        $absensi = Absensi::query()->findOrFail($id);
        Jangkauan::pastikanTerlihat($absensi);

        return ($absensi->foto_path === null ? null : $media->responsPrivat($absensi->foto_path)) ?? abort(404);
    }

    /**
     * Mengoreksi status absen masuk dengan catatan wajib. Pengoreksi dan waktunya tersimpan di absensi itu.
     */
    public function koreksi(KoreksiAbsensiRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $absensi = $this->absensiService->koreksi(Absensi::query()->findOrFail($id), $request->status(), $request->catatan(), $user);

        return ApiResponse::success(new AbsensiResource($absensi), "Status absensi dikoreksi menjadi {$request->status()->label()}.");
    }

    /**
     * Rekap satu bulan per peserta: jumlah hadir, terlambat, tidak hadir, dan hari yang tidak absen pulang.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: list<array{
     *         user: array{id: int, nama: string, jabatan: string|null},
     *         hadir: int, terlambat: int, tidak_hadir: int, tidak_absen_pulang: int
     *     }>,
     *     meta: null
     * }
     */
    public function rekap(BulanAbsensiRequest $request, RekapAbsensiService $rekap): JsonResponse
    {
        return ApiResponse::success($rekap->rekap($request->bulan()));
    }

    /**
     * File CSV berisi rekap yang sama dengan `GET /absensi/rekap`.
     */
    #[Response(200, 'Rekap absensi (.csv)', mediaType: 'text/csv', type: 'string', format: 'binary')]
    public function export(BulanAbsensiRequest $request, RekapAbsensiService $rekap): HttpResponse
    {
        $bulan = $request->bulan();

        return response($rekap->csv($bulan), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"rekap-absensi-{$bulan->format('Y-m')}.csv\"",
        ]);
    }
}
