<?php

namespace App\Http\Controllers\Api\V1\Absensi;

use App\Enums\JenisAbsensi;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsenRequest;
use App\Http\Requests\Absensi\BulanAbsensiRequest;
use App\Http\Requests\Absensi\HarianAbsensiRequest;
use App\Http\Requests\Absensi\KoreksiAbsensiBaruRequest;
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
     * Sebelum `tanggal_mulai`, `terbuka` selalu false. `pulang.boleh_lebih_awal` true kalau absen pulang sekarang
     * akan diterima sebagai pulang lebih awal (dengan keterangan): hari kerja, tidak sebelum `tanggal_mulai`, jam
     * pulang belum buka, absen masuk hari ini hadir atau terlambat, dan belum absen pulang.
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
     *         pulang: array{buka: string, tutup: string, terbuka: bool, absensi: AbsensiResource|null, boleh_lebih_awal: bool}
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
            'pulang' => [
                ...$aturan->jamPulang,
                ...$jenis(JenisAbsensi::Pulang),
                'boleh_lebih_awal' => $berlaku && $aturan->belumBuka(JenisAbsensi::Pulang, $sekarang)
                    && $tercatat->get(JenisAbsensi::Masuk->value)?->status?->perluAbsenPulang() === true
                    && ! $tercatat->has(JenisAbsensi::Pulang->value),
            ],
        ]);
    }

    /**
     * Absen masuk atau pulang (`multipart/form-data`). Ditolak 422 `BUSINESS_RULE` dengan pesan yang
     * menjelaskan sebabnya: sebelum tanggal mulai absensi, bukan hari kerja, tanggal libur, di luar jam, sudah absen jenis itu hari ini, absen
     * pulang tanpa absen masuk hadir atau terlambat, akurasi lokasi melebihi batas, atau di luar radius sekolah. Status absen masuk
     * `hadir` atau `terlambat` ditentukan dari jam server. Absen masuk dengan `status` `izin` atau `sakit` tidak butuh
     * lokasi, tetapi butuh `keterangan` dan foto; hari itu tidak ada absen pulang. Absen pulang sebelum jam pulang
     * buka diterima sebagai pulang lebih awal (`pulang_awal` true) kalau `keterangan` diisi, tanpa persetujuan.
     * Dibatasi 10 kali per menit per pengguna.
     */
    public function store(AbsenRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $absensi = $this->absensiService->absen(
            $user,
            $request->jenis(),
            $request->berhalangan(),
            $request->posisi(),
            $request->keterangan(),
            $request->foto(),
        );

        return ApiResponse::success(
            new AbsensiResource($absensi),
            "{$absensi->namaAbsen()} tercatat pukul {$absensi->waktu?->format('H:i')}.",
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
     * Mencatat absen masuk atau pulang (`jenis`, bawaan `masuk`) untuk peserta yang belum punya absen jenis itu pada
     * tanggal tersebut, misalnya guru lupa absen padahal masuk atau lupa absen pulang. Catatan wajib; `status` wajib
     * untuk absen masuk dan tidak boleh dikirim untuk absen pulang; waktu, lokasi, dan foto kosong. Ditolak 422
     * `BUSINESS_RULE` kalau akun bukan peserta absensi, tanggal setelah hari ini atau sebelum tanggal mulai absensi,
     * bukan hari kerja, tanggal libur, absen jenis itu sudah ada (absen masuk dikoreksi lewat
     * `PATCH /absensi/{id}/koreksi`), atau untuk absen pulang, belum ada absen masuk hadir atau terlambat.
     */
    public function koreksiBaru(KoreksiAbsensiBaruRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $absensi = $this->absensiService->koreksiBaru($request->integer('user_id'), $request->tanggal(), $request->jenis(), $request->status(), $request->catatan(), $user);

        return ApiResponse::success(
            new AbsensiResource($absensi),
            "Absen {$absensi->jenis->value} dicatat".($absensi->status === null ? '.' : " dengan status {$absensi->status->label()}."),
            status: 201,
        );
    }

    /**
     * Absen masuk dan pulang semua peserta pada satu tanggal (bawaan hari ini), urut nama. Pesertanya sama dengan
     * rekap: guru dan Kepala Sekolah yang aktif, ditambah akun nonaktif yang punya absensi di tanggal itu.
     * `hari_kerja`, `tanggal_libur`, dan `tanggal_mulai` sama artinya dengan di `GET /absensi/hari-ini`.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: array{
     *         tanggal: string,
     *         hari_kerja: bool,
     *         tanggal_libur: bool,
     *         tanggal_mulai: string|null,
     *         peserta: list<array{
     *             user: array{id: int, nama: string, jabatan: string|null},
     *             masuk: AbsensiResource|null,
     *             pulang: AbsensiResource|null
     *         }>
     *     },
     *     meta: null
     * }
     */
    public function harian(HarianAbsensiRequest $request, RekapAbsensiService $rekap): JsonResponse
    {
        $tanggal = $request->tanggal();
        $aturan = $this->absensiService->aturan();
        $libur = $aturan->tanggalLibur($tanggal);
        $resource = fn (?Absensi $absensi): ?AbsensiResource => $absensi === null ? null : new AbsensiResource($absensi);

        return ApiResponse::success([
            'tanggal' => $tanggal->toDateString(),
            'hari_kerja' => $aturan->hariKerja($tanggal) && ! $libur,
            'tanggal_libur' => $libur,
            'tanggal_mulai' => $aturan->tanggalMulai,
            'peserta' => array_map(fn (array $baris): array => [
                'user' => $baris['user'],
                'masuk' => $resource($baris['masuk']),
                'pulang' => $resource($baris['pulang']),
            ], $rekap->harian($tanggal)),
        ]);
    }

    /**
     * Rekap satu bulan per peserta: jumlah hadir, terlambat, izin, sakit, tidak hadir, dan hari yang tidak absen pulang.
     *
     * @response array{
     *     success: true,
     *     message: string,
     *     data: list<array{
     *         user: array{id: int, nama: string, jabatan: string|null},
     *         hadir: int, terlambat: int, izin: int, sakit: int, tidak_hadir: int, tidak_absen_pulang: int
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
