<?php

use App\Enums\Agama;
use App\Enums\GolonganDarah;
use App\Enums\Hubungan;
use App\Enums\HubunganWali;
use App\Enums\Imunisasi;
use App\Enums\JenisAbsensi;
use App\Enums\JenisAgenda;
use App\Enums\JenisKelamin;
use App\Enums\JenisNotifikasi;
use App\Enums\Kewarganegaraan;
use App\Enums\KodeError;
use App\Enums\MetodeBayar;
use App\Enums\NadaInfo;
use App\Enums\Pekerjaan;
use App\Enums\PendidikanTerakhir;
use App\Enums\Penghasilan;
use App\Enums\PeriodeTagihan;
use App\Enums\Role;
use App\Enums\StatusAbsensi;
use App\Enums\StatusAbsensiMurid;
use App\Enums\StatusAkun;
use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Enums\StatusOrangTua;
use App\Enums\StatusPembayaran;
use App\Enums\StatusPendaftaran;
use App\Enums\StatusRapor;
use App\Enums\StatusTagihan;
use App\Enums\StatusYatimPiatu;
use App\Enums\TargetPengumuman;
use App\Enums\Tingkat;
use App\Enums\TipeKeringanan;

it('memakai nilai enum persis seperti kontrak A4, A5, dan A7', function (string $enum, array $nilai) {
    expect(array_column($enum::cases(), 'value'))->toBe($nilai);
})->with([
    'Role' => [Role::class, ['super_admin', 'guru', 'wali_murid']],
    'StatusAkun' => [StatusAkun::class, ['aktif', 'nonaktif']],
    'StatusMurid' => [StatusMurid::class, ['aktif', 'lulus', 'pindah', 'keluar']],
    'Hubungan' => [Hubungan::class, ['ayah', 'ibu', 'wali']],
    'Tingkat' => [Tingkat::class, ['A', 'B']],
    'StatusKelasMurid' => [StatusKelasMurid::class, ['aktif', 'naik', 'tinggal', 'lulus', 'keluar']],
    'PeriodeTagihan' => [PeriodeTagihan::class, ['bulanan', 'sekali']],
    'TipeKeringanan' => [TipeKeringanan::class, ['persen', 'nominal']],
    'StatusTagihan' => [StatusTagihan::class, ['belum_bayar', 'menunggu_verifikasi', 'lunas', 'terlambat', 'dibatalkan']],
    'MetodeBayar' => [MetodeBayar::class, ['transfer', 'tunai']],
    'StatusPembayaran' => [StatusPembayaran::class, ['menunggu', 'diterima', 'ditolak', 'dibatalkan']],
    'TargetPengumuman' => [TargetPengumuman::class, ['semua', 'guru', 'wali_murid', 'kelas', 'murid']],
    'JenisAgenda' => [JenisAgenda::class, ['kegiatan', 'libur', 'rapat', 'lainnya']],
    'StatusRapor' => [StatusRapor::class, ['draft', 'diajukan', 'revisi', 'terbit']],
    'StatusPendaftaran' => [StatusPendaftaran::class, ['diajukan', 'diverifikasi', 'diterima', 'ditolak', 'mengundurkan_diri']],
    'Agama' => [Agama::class, ['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Konghucu']],
    'Kewarganegaraan' => [Kewarganegaraan::class, ['wni', 'wna']],
    'StatusYatimPiatu' => [StatusYatimPiatu::class, ['tidak', 'yatim', 'piatu', 'yatim_piatu']],
    'GolonganDarah' => [GolonganDarah::class, ['A', 'B', 'AB', 'O', 'tidak_tahu']],
    'Imunisasi' => [Imunisasi::class, ['bcg', 'hepatitis_b', 'polio', 'dpt', 'campak_mr']],
    'StatusOrangTua' => [StatusOrangTua::class, ['kandung', 'tiri', 'angkat']],
    'HubunganWali' => [HubunganWali::class, ['kakek', 'nenek', 'paman', 'bibi', 'kakak', 'lainnya']],
    'PendidikanTerakhir' => [PendidikanTerakhir::class, ['tidak_sekolah', 'sd', 'smp', 'slta', 'd1_d3', 'd4_s1', 's2', 's3']],
    'Pekerjaan' => [Pekerjaan::class, [
        'tidak_bekerja', 'mengurus_rumah_tangga', 'pns_tni_polri', 'karyawan_swasta', 'karyawan_bumn', 'wiraswasta', 'pedagang',
        'petani_peternak', 'nelayan', 'buruh', 'pensiunan', 'lainnya',
    ]],
    'Penghasilan' => [Penghasilan::class, ['di_bawah_500rb', '500rb_1jt', '1jt_2jt', '2jt_5jt', '5jt_20jt', 'di_atas_20jt', 'tidak_berpenghasilan']],
    'NadaInfo' => [NadaInfo::class, ['info', 'penting', 'peringatan']],
    'JenisAbsensi' => [JenisAbsensi::class, ['masuk', 'pulang']],
    'StatusAbsensi' => [StatusAbsensi::class, ['hadir', 'terlambat', 'tidak_hadir', 'izin', 'sakit']],
    'StatusAbsensiMurid' => [StatusAbsensiMurid::class, ['hadir', 'izin', 'sakit', 'alpa']],
    'JenisKelamin (A4)' => [JenisKelamin::class, ['L', 'P']],
    'JenisNotifikasi (A7)' => [JenisNotifikasi::class, [
        'tagihan_baru', 'tagihan_tertunda', 'pengingat_tagihan', 'tagihan_terlambat', 'pembayaran_masuk',
        'pembayaran_diterima', 'pembayaran_ditolak', 'pembayaran_dibatalkan', 'rapor_diajukan', 'rapor_revisi', 'rapor_terbit',
        'pengumuman_baru', 'pendaftaran_baru', 'pendaftaran_diproses', 'anak_tertaut', 'username_diubah', 'absensi_tercatat',
        'absensi_dikoreksi',
        'absensi_susulan_diberikan',
    ]],
    'KodeError' => [KodeError::class, [
        'UNAUTHENTICATED', 'FORBIDDEN', 'ACCOUNT_INACTIVE',
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
    StatusRapor::class, StatusPendaftaran::class, JenisKelamin::class, JenisNotifikasi::class,
    NadaInfo::class, JenisAbsensi::class, StatusAbsensi::class, StatusAbsensiMurid::class,
    Agama::class, Kewarganegaraan::class, StatusYatimPiatu::class, GolonganDarah::class, Imunisasi::class,
    StatusOrangTua::class, HubunganWali::class, PendidikanTerakhir::class, Pekerjaan::class, Penghasilan::class,
]);
