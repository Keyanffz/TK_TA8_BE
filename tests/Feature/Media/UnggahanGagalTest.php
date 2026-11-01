<?php

use App\Models\GaleriAlbum;
use App\Models\Pengumuman;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();

    $this->kepsek = buatKepalaSekolah();
});

/**
 * File yang ditolak PHP sebelum sampai ke validasi aplikasi. Dalam mode test, `isValid()` hanya melihat kode galatnya.
 */
function unggahanDitolakPhp(string $nama, string $mime, int $kodeGalat): UploadedFile
{
    return new UploadedFile(__FILE__, $nama, $mime, $kodeGalat, true);
}

function pengumumanDenganLampiran(UploadedFile $lampiran): array
{
    return [
        'judul' => 'Jadwal lomba mewarnai',
        'isi' => '<p>Jadwal lengkap ada di lampiran.</p>',
        'target' => 'semua',
        'publish' => '1',
        'lampiran' => $lampiran,
    ];
}

it('menyebut batas unggah server saat lampiran lebih besar dari batas PHP', function () {
    $response = $this->actingAs($this->kepsek)
        ->post('/api/v1/pengumuman', pengumumanDenganLampiran(unggahanDitolakPhp('Jadwal Lomba.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE)))
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    expect($response->json('errors.lampiran.0'))
        ->toMatch('/^Maaf, lampiran gagal diunggah karena ukurannya lebih dari \d+(,\d)? (MB|KB)\. Pilih file yang lebih kecil\.$/')
        ->and(Pengumuman::count())->toBe(0);
});

it('meminta memeriksa sambungan saat unggahan lampiran terputus', function () {
    $this->actingAs($this->kepsek)
        ->post('/api/v1/pengumuman', pengumumanDenganLampiran(unggahanDitolakPhp('Jadwal Lomba.pdf', 'application/pdf', UPLOAD_ERR_PARTIAL)))
        ->assertStatus(422)
        ->assertJsonPath('errors.lampiran.0', 'Maaf, lampiran gagal diunggah karena sambungan terputus. Periksa sambungan internet, lalu coba lagi.');
});

it('meminta mencoba lagi nanti saat server gagal menyimpan unggahan', function () {
    $this->actingAs($this->kepsek)
        ->post('/api/v1/pengumuman', pengumumanDenganLampiran(unggahanDitolakPhp('Jadwal Lomba.pdf', 'application/pdf', UPLOAD_ERR_CANT_WRITE)))
        ->assertStatus(422)
        ->assertJsonPath('errors.lampiran.0', 'Maaf, lampiran gagal diunggah karena ada gangguan di server sekolah. Coba unggah ulang beberapa saat lagi.');
});

it('menyebut urutan foto yang gagal diunggah di antara beberapa foto', function () {
    $album = GaleriAlbum::factory()->create();

    $this->actingAs($this->kepsek)
        ->post("/api/v1/galeri-album/{$album->id}/foto", [
            'foto' => [UploadedFile::fake()->image('panggung.jpg'), unggahanDitolakPhp('penonton.jpg', 'image/jpeg', UPLOAD_ERR_PARTIAL)],
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors', ['foto.1' => ['Maaf, foto ke-2 gagal diunggah karena sambungan terputus. Periksa sambungan internet, lalu coba lagi.']]);
});

it('menyebut foto dengan urutannya di pesan validasi lain', function () {
    $album = GaleriAlbum::factory()->create();

    $response = $this->actingAs($this->kepsek)
        ->post("/api/v1/galeri-album/{$album->id}/foto", ['foto' => [UploadedFile::fake()->create('daftar-hadir.txt', 10, 'text/plain')]])
        ->assertStatus(422);

    expect($response->json('errors')['foto.0'])->toContain('Foto ke-1 harus berupa gambar.');
});
