<?php

namespace App\Http\Controllers\Api\V1\Pengaturan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pengaturan\DaftarPengaturanRequest;
use App\Http\Requests\Pengaturan\SimpanPengaturanRequest;
use App\Http\Requests\Pengaturan\UnggahGambarPengaturanRequest;
use App\Models\User;
use App\Services\MediaService;
use App\Services\PengaturanService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

class PengaturanController extends Controller
{
    public function __construct(private readonly PengaturanService $pengaturan) {}

    /**
     * Pengaturan sebagai objek datar berkunci lengkap (`"profil.visi": …`), bisa dibatasi per `grup`
     * (`profil` | `landing` | `keuangan` | `ppdb` | `beranda` | `absensi`). Field gambar disertai pasangan `*_url`.
     *
     * Tanpa `grup` hanya untuk Kepala Sekolah. Guru: grup `profil`, `landing`, `beranda` dengan izin lihat konten
     * website; grup `keuangan` dengan izin lihat keuangan; grup `ppdb` dan `absensi` ditolak 403.
     */
    public function index(DaftarPengaturanRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $grup = $request->grup();
        $this->pengaturan->pastikanBolehBaca($user, $grup);

        return ApiResponse::success($this->pengaturan->untukRespons($grup === null ? null : [$grup]));
    }

    /**
     * Menyimpan sebagian pengaturan `{ items: { kunci: nilai } }`. Kunci yang tidak dikirim tidak berubah;
     * field `*_url` diabaikan. `ppdb.dibuka = true` ditolak kalau `ppdb.tahun_ajaran_id` belum diisi.
     *
     * Guru dengan izin kelola konten website hanya boleh mengirim kunci grup `profil`, `landing`, dan `beranda`;
     * kunci grup lain (rekening dan aturan tagihan, PPDB, absensi) membuat seluruh permintaan ditolak 403.
     */
    public function update(SimpanPengaturanRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->pengaturan->pastikanBolehUbah($user, $request->items());
        $this->pengaturan->simpan($request->items(), $user);

        return ApiResponse::success($this->pengaturan->untukRespons(), 'Pengaturan tersimpan.');
    }

    /**
     * Mengunggah gambar untuk pengaturan (logo, gambar hero, gambar fasilitas). Kirim `path` hasilnya lewat
     * `PUT /pengaturan`.
     */
    public function upload(UnggahGambarPengaturanRequest $request, MediaService $media): JsonResponse
    {
        $path = $media->simpanGambar($request->gambar(), MediaService::DISK_PUBLIK, PengaturanService::FOLDER_GAMBAR);

        return ApiResponse::success(['path' => $path, 'url' => (string) $media->urlPublik($path)], 'Gambar terunggah.', status: 201);
    }
}
