<?php

use App\Http\Controllers\Api\V1\Agenda\AgendaController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ProfilController;
use App\Http\Controllers\Api\V1\Auth\RegistrasiGuruController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Galeri\GaleriController;
use App\Http\Controllers\Api\V1\Guru\GuruController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Kegiatan\KegiatanKelasController;
use App\Http\Controllers\Api\V1\Kelas\KelasController;
use App\Http\Controllers\Api\V1\Kelas\PenempatanMuridController;
use App\Http\Controllers\Api\V1\Keuangan\JenisTagihanController;
use App\Http\Controllers\Api\V1\Keuangan\KeringananController;
use App\Http\Controllers\Api\V1\Keuangan\LaporanController;
use App\Http\Controllers\Api\V1\Keuangan\PembayaranController;
use App\Http\Controllers\Api\V1\Keuangan\TagihanController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\Murid\MuridController;
use App\Http\Controllers\Api\V1\Notifikasi\NotifikasiController;
use App\Http\Controllers\Api\V1\Pengaturan\PengaturanController;
use App\Http\Controllers\Api\V1\Pengumuman\PengumumanController;
use App\Http\Controllers\Api\V1\Ppdb\PendaftaranController;
use App\Http\Controllers\Api\V1\Publik\PublikController;
use App\Http\Controllers\Api\V1\Rapor\ElemenPenilaianController;
use App\Http\Controllers\Api\V1\Rapor\RaporController;
use App\Http\Controllers\Api\V1\TahunAjaran\TahunAjaranController;
use App\Http\Controllers\Api\V1\Wali\AnakController;
use App\Http\Controllers\Api\V1\Wali\ProfilWaliController;
use App\Http\Controllers\Api\V1\WaliMurid\WaliMuridController;
use Illuminate\Support\Facades\Route;

Route::pattern('id', '[0-9]+');
Route::pattern('murid_id', '[0-9]+');
Route::pattern('wali_murid_id', '[0-9]+');
Route::pattern('detail_id', '[0-9]+');

Route::get('/health', HealthController::class)->name('health');
Route::get('/media/{token}', MediaController::class)->middleware('signed:relative')->name('media');

Route::prefix('public')->group(function () {
    Route::get('/profil', [PublikController::class, 'profil']);
    Route::get('/pengumuman', [PublikController::class, 'pengumuman']);
    Route::get('/pengumuman/{slug}', [PublikController::class, 'detailPengumuman']);
    Route::get('/agenda', [PublikController::class, 'agenda']);
    Route::get('/galeri', [PublikController::class, 'galeri']);
    Route::get('/galeri/{slug}', [PublikController::class, 'detailGaleri']);
    Route::get('/guru', [PublikController::class, 'guru']);
    Route::get('/ppdb', [PublikController::class, 'ppdb']);
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/google', [AuthController::class, 'google'])->middleware('throttle:login-google');
    Route::post('/register-guru', RegistrasiGuruController::class);
    Route::post('/forgot-password', [ResetPasswordController::class, 'kirimTautan']);
    Route::post('/reset-password', [ResetPasswordController::class, 'reset']);
});

Route::middleware(['auth:sanctum', 'akun.aktif'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::put('/profil', [ProfilController::class, 'perbarui']);
        Route::put('/password', [ProfilController::class, 'gantiPassword'])->middleware('role:super_admin,guru');
    });

    Route::middleware('role:super_admin')->group(function () {
        Route::get('/guru', [GuruController::class, 'index']);
        Route::post('/guru', [GuruController::class, 'store']);
        Route::get('/guru/{id}', [GuruController::class, 'show']);
        Route::put('/guru/{id}', [GuruController::class, 'update']);
        Route::post('/guru/{id}/setujui', [GuruController::class, 'setujui']);
        Route::post('/guru/{id}/tolak', [GuruController::class, 'tolak']);
        Route::patch('/guru/{id}/status', [GuruController::class, 'ubahStatus']);

        Route::get('/wali-murid', [WaliMuridController::class, 'index']);
        Route::get('/wali-murid/{id}', [WaliMuridController::class, 'show']);
        Route::patch('/wali-murid/{id}/status', [WaliMuridController::class, 'ubahStatus']);

        Route::post('/tahun-ajaran', [TahunAjaranController::class, 'store']);
        Route::put('/tahun-ajaran/{id}', [TahunAjaranController::class, 'update']);
        Route::delete('/tahun-ajaran/{id}', [TahunAjaranController::class, 'destroy']);
        Route::post('/tahun-ajaran/{id}/aktifkan', [TahunAjaranController::class, 'aktifkan']);

        Route::post('/kelas', [KelasController::class, 'store']);
        Route::put('/kelas/{id}', [KelasController::class, 'update']);
        Route::delete('/kelas/{id}', [KelasController::class, 'destroy']);
        Route::post('/kelas/{id}/murid', [PenempatanMuridController::class, 'tempatkan']);
        Route::delete('/kelas/{id}/murid/{murid_id}', [PenempatanMuridController::class, 'keluarkan']);
        Route::post('/kelas/kenaikan', [PenempatanMuridController::class, 'kenaikan']);

        Route::post('/murid', [MuridController::class, 'store']);
        Route::put('/murid/{id}', [MuridController::class, 'update']);
        Route::delete('/murid/{id}', [MuridController::class, 'destroy']);
        Route::post('/murid/{id}/kode-tautan', [MuridController::class, 'kodeTautan']);
        Route::delete('/murid/{id}/wali/{wali_murid_id}', [MuridController::class, 'lepasWali']);

        Route::post('/jenis-tagihan', [JenisTagihanController::class, 'store']);
        Route::put('/jenis-tagihan/{id}', [JenisTagihanController::class, 'update']);
        Route::delete('/jenis-tagihan/{id}', [JenisTagihanController::class, 'destroy']);
        Route::post('/tagihan/generate', [TagihanController::class, 'generate']);
        Route::patch('/tagihan/{id}/batalkan', [TagihanController::class, 'batalkan']);

        Route::post('/rapor/{id}/terbitkan', [RaporController::class, 'terbitkan']);
        Route::post('/rapor/{id}/revisi', [RaporController::class, 'revisi']);

        Route::post('/elemen-penilaian', [ElemenPenilaianController::class, 'store']);
        Route::put('/elemen-penilaian/{id}', [ElemenPenilaianController::class, 'update']);
        Route::delete('/elemen-penilaian/{id}', [ElemenPenilaianController::class, 'destroy']);

        Route::put('/pengaturan', [PengaturanController::class, 'update']);
        Route::post('/pengaturan/upload', [PengaturanController::class, 'upload']);

        Route::get('/galeri-album', [GaleriController::class, 'index']);
        Route::post('/galeri-album', [GaleriController::class, 'store']);
        Route::put('/galeri-album/{id}', [GaleriController::class, 'update']);
        Route::delete('/galeri-album/{id}', [GaleriController::class, 'destroy']);
        Route::post('/galeri-album/{id}/foto', [GaleriController::class, 'tambahFoto']);
        Route::put('/galeri-foto/{id}', [GaleriController::class, 'perbaruiFoto']);
        Route::delete('/galeri-foto/{id}', [GaleriController::class, 'hapusFoto']);

        Route::post('/pendaftaran/{id}/verifikasi', [PendaftaranController::class, 'verifikasi']);
        Route::post('/pendaftaran/{id}/terima', [PendaftaranController::class, 'terima']);
        Route::post('/pendaftaran/{id}/tolak', [PendaftaranController::class, 'tolak']);

        Route::post('/agenda', [AgendaController::class, 'store']);
        Route::put('/agenda/{id}', [AgendaController::class, 'update']);
        Route::delete('/agenda/{id}', [AgendaController::class, 'destroy']);
    });

    Route::middleware('can:kelola-keuangan')->group(function () {
        Route::get('/pengaturan', [PengaturanController::class, 'index']);
        Route::get('/jenis-tagihan', [JenisTagihanController::class, 'index']);

        Route::get('/keringanan', [KeringananController::class, 'index']);
        Route::post('/keringanan', [KeringananController::class, 'store']);
        Route::put('/keringanan/{id}', [KeringananController::class, 'update']);
        Route::delete('/keringanan/{id}', [KeringananController::class, 'destroy']);

        Route::post('/tagihan', [TagihanController::class, 'store']);
        Route::post('/pembayaran/{id}/terima', [PembayaranController::class, 'terima']);
        Route::post('/pembayaran/{id}/tolak', [PembayaranController::class, 'tolak']);

        Route::get('/laporan/keuangan', [LaporanController::class, 'keuangan']);
        Route::get('/laporan/keuangan/export', [LaporanController::class, 'export']);
        Route::get('/laporan/tunggakan', [LaporanController::class, 'tunggakan']);
    });

    Route::middleware('role:super_admin,guru')->group(function () {
        Route::get('/tahun-ajaran', [TahunAjaranController::class, 'index']);
        Route::get('/kelas', [KelasController::class, 'index']);
        Route::get('/kelas/{id}', [KelasController::class, 'show']);
        Route::get('/elemen-penilaian', [ElemenPenilaianController::class, 'index']);

        Route::post('/kegiatan', [KegiatanKelasController::class, 'store']);
        Route::put('/kegiatan/{id}', [KegiatanKelasController::class, 'update']);
        Route::delete('/kegiatan/{id}', [KegiatanKelasController::class, 'destroy']);
        Route::post('/kegiatan/{id}/foto', [KegiatanKelasController::class, 'tambahFoto']);
        Route::delete('/kegiatan-foto/{id}', [KegiatanKelasController::class, 'hapusFoto']);

        Route::post('/rapor', [RaporController::class, 'store']);
        Route::put('/rapor/{id}', [RaporController::class, 'update']);
        Route::post('/rapor/{id}/detail/{detail_id}/foto', [RaporController::class, 'foto']);
        Route::post('/rapor/{id}/ajukan', [RaporController::class, 'ajukan']);

        Route::post('/pengumuman', [PengumumanController::class, 'store']);
        Route::put('/pengumuman/{id}', [PengumumanController::class, 'update']);
        Route::delete('/pengumuman/{id}', [PengumumanController::class, 'destroy']);
    });

    Route::get('/murid', [MuridController::class, 'index']);
    Route::get('/murid/{id}', [MuridController::class, 'show']);

    Route::get('/tagihan', [TagihanController::class, 'index']);
    Route::get('/tagihan/{id}', [TagihanController::class, 'show']);
    Route::post('/tagihan/{id}/pembayaran', [PembayaranController::class, 'bayar']);

    Route::get('/pembayaran', [PembayaranController::class, 'index']);
    Route::get('/pembayaran/{id}', [PembayaranController::class, 'show']);
    Route::get('/pembayaran/{id}/bukti', [PembayaranController::class, 'bukti']);
    Route::get('/pembayaran/{id}/kwitansi', [PembayaranController::class, 'kwitansi']);

    Route::get('/kegiatan', [KegiatanKelasController::class, 'index']);
    Route::get('/kegiatan/{id}', [KegiatanKelasController::class, 'show']);

    Route::get('/rapor', [RaporController::class, 'index']);
    Route::get('/rapor/{id}', [RaporController::class, 'show']);
    Route::get('/rapor/{id}/pdf', [RaporController::class, 'pdf']);

    Route::get('/pengumuman', [PengumumanController::class, 'index']);
    Route::get('/pengumuman/{id}', [PengumumanController::class, 'show']);

    Route::get('/agenda', [AgendaController::class, 'index']);

    Route::get('/notifikasi', [NotifikasiController::class, 'index']);
    Route::get('/notifikasi/belum-dibaca', [NotifikasiController::class, 'belumDibaca']);
    Route::post('/notifikasi/baca-semua', [NotifikasiController::class, 'bacaSemua']);
    Route::post('/notifikasi/{id}/baca', [NotifikasiController::class, 'baca'])->whereUuid('id');

    Route::middleware('role:super_admin,wali_murid')->group(function () {
        Route::get('/pendaftaran', [PendaftaranController::class, 'index']);
        Route::get('/pendaftaran/{id}', [PendaftaranController::class, 'show']);
    });
    Route::post('/pendaftaran', [PendaftaranController::class, 'store'])->middleware('role:wali_murid');

    Route::middleware('role:wali_murid')->prefix('wali')->group(function () {
        Route::put('/profil', ProfilWaliController::class);
        Route::post('/tautkan-anak', [AnakController::class, 'tautkan'])->middleware('throttle:tautkan-anak');
        Route::get('/anak', [AnakController::class, 'index']);
    });
});
