<?php

use App\Enums\Hubungan;
use App\Enums\JenisAgenda;
use App\Enums\JenisDokumen;
use App\Enums\JenisKelamin;
use App\Enums\JenisNotifikasi;
use App\Enums\KodeError;
use App\Enums\MetodeBayar;
use App\Enums\NadaInfo;
use App\Enums\PeriodeTagihan;
use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Enums\StatusPembayaran;
use App\Enums\StatusPendaftaran;
use App\Enums\StatusRapor;
use App\Enums\StatusTagihan;
use App\Enums\TargetPengumuman;
use App\Enums\Tingkat;
use App\Enums\TipeKeringanan;

it('memakai nilai enum persis seperti kontrak A4, A5, dan A7', function (string $enum, array $nilai) {
    expect(array_column($enum::cases(), 'value'))->toBe($nilai);
})->with([
    'Role' => [Role::class, ['super_admin', 'guru', 'wali_murid']],
    'StatusAkun' => [StatusAkun::class, ['pending', 'aktif', 'ditolak', 'nonaktif']],
    'StatusMurid' => [StatusMurid::class, ['aktif', 'lulus', 'pindah', 'keluar']],
    'Hubungan' => [Hubungan::class, ['ayah', 'ibu', 'wali']],
    'Tingkat' => [Tingkat::class, ['A', 'B']],
    'StatusKelasMurid' => [StatusKelasMurid::class, ['aktif', 'naik', 'tinggal', 'lulus', 'keluar']],
    'PeriodeTagihan' => [PeriodeTagihan::class, ['bulanan', 'sekali']],
    'TipeKeringanan' => [TipeKeringanan::class, ['persen', 'nominal']],
    'StatusTagihan' => [StatusTagihan::class, ['belum_bayar', 'menunggu_verifikasi', 'lunas', 'terlambat', 'dibatalkan']],
    'MetodeBayar' => [MetodeBayar::class, ['transfer', 'tunai']],
    'StatusPembayaran' => [StatusPembayaran::class, ['menunggu', 'diterima', 'ditolak']],
    'TargetPengumuman' => [TargetPengumuman::class, ['semua', 'guru', 'wali_murid', 'kelas', 'murid']],
    'JenisAgenda' => [JenisAgenda::class, ['kegiatan', 'libur', 'rapat', 'lainnya']],
    'StatusRapor' => [StatusRapor::class, ['draft', 'diajukan', 'revisi', 'terbit']],
    'StatusPendaftaran' => [StatusPendaftaran::class, ['diajukan', 'diverifikasi', 'diterima', 'ditolak']],
    'JenisDokumen' => [JenisDokumen::class, ['akta_kelahiran', 'kartu_keluarga', 'pas_foto', 'lainnya']],
    'NadaInfo' => [NadaInfo::class, ['info', 'penting', 'peringatan']],
    'JenisKelamin (A4)' => [JenisKelamin::class, ['L', 'P']],
    'JenisNotifikasi (A7)' => [JenisNotifikasi::class, [
        'tagihan_baru', 'tagihan_tertunda', 'pengingat_tagihan', 'tagihan_terlambat', 'pembayaran_masuk',
        'pembayaran_diterima', 'pembayaran_ditolak', 'guru_baru', 'rapor_diajukan', 'rapor_revisi', 'rapor_terbit',
        'pengumuman_baru', 'pendaftaran_baru', 'pendaftaran_diproses', 'anak_tertaut',
    ]],
    'KodeError' => [KodeError::class, [
        'UNAUTHENTICATED', 'FORBIDDEN', 'ACCOUNT_PENDING', 'ACCOUNT_REJECTED', 'ACCOUNT_INACTIVE',
        'PASSWORD_WAJIB_DIGANTI', 'NOT_FOUND', 'VALIDATION_ERROR', 'BUSINESS_RULE', 'TOO_MANY_REQUESTS', 'SERVER_ERROR',
    ]],
]);

it('memberi label bahasa Indonesia untuk setiap nilai enum', function (string $enum) {
    foreach ($enum::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
})->with([
    Role::class, StatusAkun::class, StatusMurid::class, Hubungan::class, Tingkat::class,
    StatusKelasMurid::class, PeriodeTagihan::class, TipeKeringanan::class, StatusTagihan::class,
    MetodeBayar::class, StatusPembayaran::class, TargetPengumuman::class, JenisAgenda::class,
    StatusRapor::class, StatusPendaftaran::class, JenisDokumen::class, JenisKelamin::class, JenisNotifikasi::class,
    NadaInfo::class,
]);
