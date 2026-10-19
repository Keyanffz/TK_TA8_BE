<?php

use App\Enums\ModulIzin;
use App\Models\Guru;
use App\Models\Murid;
use App\Models\User;
use App\Models\WaliMurid;
use Illuminate\Support\Facades\Storage;

/**
 * Route yang dibuka per modul izin (A2.13). Isian sengaja kosong: lolos izin berarti tidak 403 (validasi boleh 422).
 */
dataset('rute modul', [
    'daftar jenis tagihan' => ['keuangan', 'lihat', 'GET', '/api/v1/jenis-tagihan'],
    'daftar keringanan' => ['keuangan', 'lihat', 'GET', '/api/v1/keringanan'],
    'laporan keuangan' => ['keuangan', 'lihat', 'GET', '/api/v1/laporan/keuangan?dari=2026-07-01&sampai=2026-09-30'],
    'tunggakan' => ['keuangan', 'lihat', 'GET', '/api/v1/laporan/tunggakan'],
    'tambah keringanan' => ['keuangan', 'kelola', 'POST', '/api/v1/keringanan'],
    'tagihan sekali bayar' => ['keuangan', 'kelola', 'POST', '/api/v1/tagihan'],
    'daftar album galeri' => ['konten_website', 'lihat', 'GET', '/api/v1/galeri-album'],
    'tambah album galeri' => ['konten_website', 'kelola', 'POST', '/api/v1/galeri-album'],
    'unggah gambar CMS' => ['konten_website', 'kelola', 'POST', '/api/v1/pengaturan/upload'],
    'rekap absensi' => ['laporan_absensi', 'lihat', 'GET', '/api/v1/absensi/rekap'],
    'absensi harian' => ['laporan_absensi', 'lihat', 'GET', '/api/v1/absensi/harian'],
    'catat absensi peserta' => ['laporan_absensi', 'kelola', 'POST', '/api/v1/absensi/koreksi'],
    'tahun lulus alumni' => ['data_murid', 'lihat', 'GET', '/api/v1/murid/tahun-lulus'],
    'tambah murid' => ['data_murid', 'kelola', 'POST', '/api/v1/murid'],
    'ubah status massal' => ['data_murid', 'kelola', 'POST', '/api/v1/murid/status-massal'],
    'template import murid' => ['data_murid', 'kelola', 'GET', '/api/v1/murid/import/template'],
    'pratinjau import murid' => ['data_murid', 'kelola', 'POST', '/api/v1/murid/import/pratinjau'],
]);

function guruDenganIzin(array $izin = []): Guru
{
    $guru = buatGuru();

    foreach ($izin as $modul => $level) {
        $guru->izin()->create(['modul' => $modul, 'level' => $level]);
    }

    return $guru;
}

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    $this->kepsek = buatKepalaSekolah();
});

it('menolak guru tanpa izin modul dengan 403 dan pesan izin yang perlu diminta', function (string $modul, string $level, string $metode, string $url) {
    $label = ModulIzin::from($modul)->label();

    $this->actingAs(guruDenganIzin()->user)->json($metode, $url)
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN')
        ->assertJsonPath('message', "Anda belum punya izin {$level} {$label}. Minta Kepala Sekolah menambahkannya di halaman Hak Akses Guru.");
})->with('rute modul');

it('meloloskan guru dengan izin modul pada level yang diminta', function (string $modul, string $level, string $metode, string $url) {
    $respons = $this->actingAs(guruDenganIzin([$modul => $level])->user)->json($metode, $url);

    expect($respons->getStatusCode())->not->toBe(403);
    if ($metode === 'GET') {
        $respons->assertOk();
    }
})->with('rute modul');

it('menolak izin lihat untuk aksi kelola di setiap modul', function (string $modul, string $metode, string $url) {
    $this->actingAs(guruDenganIzin([$modul => 'lihat'])->user)->json($metode, $url)
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
})->with([
    'tambah keringanan' => ['keuangan', 'POST', '/api/v1/keringanan'],
    'terima pembayaran' => ['keuangan', 'POST', '/api/v1/pembayaran/1/terima'],
    'tambah album galeri' => ['konten_website', 'POST', '/api/v1/galeri-album'],
    'catat absensi peserta' => ['laporan_absensi', 'POST', '/api/v1/absensi/koreksi'],
    'tambah murid' => ['data_murid', 'POST', '/api/v1/murid'],
    'import murid' => ['data_murid', 'POST', '/api/v1/murid/import'],
]);

it('selalu meloloskan Kepala Sekolah dan selalu menolak wali murid di route modul', function (string $modul, string $level, string $metode, string $url) {
    expect($this->actingAs($this->kepsek)->json($metode, $url)->getStatusCode())->not->toBe(403);

    $wali = User::factory()->waliMurid()->create();
    WaliMurid::factory()->for($wali)->create();
    $this->actingAs($wali)->json($metode, $url)->assertForbidden();
})->with('rute modul');

it('tetap menolak guru dengan semua izin di bagian yang khusus Kepala Sekolah', function (string $metode, Closure $url, array $isian) {
    $guru = guruDenganIzin(array_fill_keys(array_column(ModulIzin::cases(), 'value'), 'kelola'));

    $this->actingAs($guru->user)->json($metode, $url($this), $isian)
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
})->with([
    'daftar guru' => ['GET', fn () => '/api/v1/guru', []],
    'daftar izin' => ['GET', fn () => '/api/v1/izin', []],
    'ubah izin guru' => ['PUT', fn () => '/api/v1/guru/'.buatGuru()->id.'/izin', []],
    'terapkan preset' => ['POST', fn () => '/api/v1/guru/'.buatGuru()->id.'/izin/preset', ['preset' => 'bendahara']],
    'baca pengaturan absensi' => ['GET', fn () => '/api/v1/pengaturan?grup=absensi', []],
    'baca semua pengaturan' => ['GET', fn () => '/api/v1/pengaturan', []],
    'ubah pengaturan absensi' => ['PUT', fn () => '/api/v1/pengaturan', ['items' => ['absensi.radius_meter' => 200]]],
    'ubah rekening sekolah' => ['PUT', fn () => '/api/v1/pengaturan', ['items' => ['keuangan.tanggal_jatuh_tempo' => 15]]],
    'ubah pengaturan PPDB' => ['PUT', fn () => '/api/v1/pengaturan', ['items' => ['ppdb.kuota' => 40]]],
    'daftar wali murid' => ['GET', fn () => '/api/v1/wali-murid', []],
    'generate tagihan' => ['POST', fn () => '/api/v1/tagihan/generate', ['periode' => '2026-10']],
    'tambah jenis tagihan' => ['POST', fn () => '/api/v1/jenis-tagihan', []],
    'log aktivitas' => ['GET', fn () => '/api/v1/log-aktivitas', []],
    'lepas tautan wali' => ['DELETE', fn () => '/api/v1/murid/'.Murid::factory()->create()->id.'/wali/1', []],
    'kenaikan kelas' => ['POST', fn () => '/api/v1/kelas/kenaikan', []],
]);

it('menerapkan pencabutan izin pada request berikutnya tanpa perlu masuk ulang', function () {
    $guru = guruDenganIzin(['data_murid' => 'lihat']);
    $token = $guru->user->createToken('web')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/murid/tahun-lulus')->assertOk();

    $this->actingAs($this->kepsek)->putJson("/api/v1/guru/{$guru->id}/izin", ['izin' => [
        'keuangan' => null, 'konten_website' => null, 'laporan_absensi' => null, 'data_murid' => null, 'pengumuman' => null,
    ]])->assertOk();
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/murid/tahun-lulus')->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.izin.data_murid', null);
});
