<?php

use App\Models\GaleriAlbum;
use App\Models\GaleriFoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->kepsek = buatKepalaSekolah();
});

function buatAlbum(object $test, array $timpa = []): int
{
    return $test->actingAs($test->kepsek)->post('/api/v1/galeri-album', [
        'judul' => 'Karnaval Kemerdekaan RI ke-81',
        'deskripsi' => 'Anak-anak berkeliling kampung dengan pakaian adat.',
        'tanggal' => '2026-08-17',
        ...$timpa,
    ])->assertCreated()->json('data.id');
}

it('membuat album tidak publik dengan slug dari judul', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/galeri-album', ['judul' => 'Karnaval Kemerdekaan RI ke-81', 'tanggal' => '2026-08-17'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'karnaval-kemerdekaan-ri-ke-81')
        ->assertJsonPath('data.is_publik', false)
        ->assertJsonPath('data.cover_url', null)
        ->assertJsonPath('data.jumlah_foto', 0);

    $this->actingAs($this->kepsek)->postJson('/api/v1/galeri-album', ['judul' => 'Karnaval Kemerdekaan RI ke-81', 'tanggal' => '2026-08-18'])
        ->assertJsonPath('data.slug', 'karnaval-kemerdekaan-ri-ke-81-2');
});

it('menambah foto berurutan dan memakai foto pertama sebagai sampul kalau tidak ada cover', function () {
    $id = buatAlbum($this);

    $response = $this->actingAs($this->kepsek)->post("/api/v1/galeri-album/{$id}/foto", ['foto' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]])
        ->assertCreated()
        ->assertJsonPath('message', '2 foto ditambahkan.')
        ->assertJsonPath('data.jumlah_foto', 2)
        ->assertJsonPath('data.foto.1.urutan', 2);

    $pertama = GaleriFoto::query()->orderBy('urutan')->firstOrFail();
    expect($pertama->path)->toStartWith('galeri/')
        ->and($response->json('data.cover_url'))->toBe(Storage::disk('public')->url($pertama->path));
    Storage::disk('public')->assertExists($pertama->path);
});

it('mengganti cover album dan menghapus file cover lama', function () {
    $id = buatAlbum($this, ['cover' => UploadedFile::fake()->image('cover.jpg')]);
    $lama = (string) GaleriAlbum::query()->findOrFail($id)->cover_path;

    $this->actingAs($this->kepsek)->put("/api/v1/galeri-album/{$id}", [
        'judul' => 'Karnaval 17 Agustus', 'tanggal' => '2026-08-17', 'is_publik' => true, 'cover' => UploadedFile::fake()->image('baru.jpg'),
    ])
        ->assertOk()
        ->assertJsonPath('data.judul', 'Karnaval 17 Agustus')
        ->assertJsonPath('data.slug', 'karnaval-kemerdekaan-ri-ke-81')
        ->assertJsonPath('data.is_publik', true);

    Storage::disk('public')->assertMissing($lama);
    Storage::disk('public')->assertExists((string) GaleriAlbum::query()->findOrFail($id)->cover_path);
});

it('mengubah keterangan foto, menghapus foto sampul, lalu menghapus album beserta semua file', function () {
    $id = buatAlbum($this);
    $this->actingAs($this->kepsek)->post("/api/v1/galeri-album/{$id}/foto", ['foto' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]]);
    $foto = GaleriFoto::query()->orderBy('urutan')->get();
    GaleriAlbum::query()->whereKey($id)->update(['cover_path' => $foto[0]->path]);

    $this->actingAs($this->kepsek)->putJson("/api/v1/galeri-foto/{$foto[1]->id}", ['caption' => 'Barisan pakaian adat', 'urutan' => 0])
        ->assertOk()
        ->assertJsonPath('data', ['id' => $foto[1]->id, 'caption' => 'Barisan pakaian adat', 'urutan' => 0]);

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/galeri-foto/{$foto[0]->id}")->assertOk();
    expect(GaleriAlbum::query()->findOrFail($id)->cover_path)->toBeNull();
    Storage::disk('public')->assertMissing($foto[0]->path);

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/galeri-album/{$id}")->assertOk();
    expect(GaleriAlbum::query()->count())->toBe(0)
        ->and(GaleriFoto::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('galeri'))->toBe([]);
});

it('menampilkan semua album dengan jumlah foto tanpa daftar foto, dengan filter publik', function () {
    GaleriAlbum::factory()->create(['is_publik' => false]);
    GaleriFoto::factory()->count(2)->for(GaleriAlbum::factory()->create(['is_publik' => true]), 'album')->create();

    $this->actingAs($this->kepsek)->getJson('/api/v1/galeri-album')->assertOk()->assertJsonPath('meta.total', 2);
    $this->actingAs($this->kepsek)->getJson('/api/v1/galeri-album?filter[is_publik]=1')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.jumlah_foto', 2)
        ->assertJsonMissingPath('data.0.foto');
});

it('menampilkan detail album beserta semua foto ke Kepala Sekolah, termasuk album yang belum publik', function () {
    $album = GaleriAlbum::factory()->create(['is_publik' => false]);
    GaleriFoto::factory()->for($album, 'album')->create(['urutan' => 2, 'path' => 'galeri/b.jpg']);
    GaleriFoto::factory()->for($album, 'album')->create(['urutan' => 1, 'path' => 'galeri/a.jpg']);

    $this->actingAs($this->kepsek)->getJson("/api/v1/galeri-album/{$album->id}")
        ->assertOk()
        ->assertJsonPath('data.jumlah_foto', 2)
        ->assertJsonCount(2, 'data.foto')
        ->assertJsonPath('data.foto.0.url', Storage::disk('public')->url('galeri/a.jpg'))
        ->assertJsonPath('data.cover_url', Storage::disk('public')->url('galeri/a.jpg'));

    $this->actingAs($this->kepsek)->getJson('/api/v1/galeri-album/999999')->assertNotFound();
    $this->actingAs(buatGuru()->user)->getJson("/api/v1/galeri-album/{$album->id}")->assertForbidden();
});

it('hanya Kepala Sekolah yang bisa mengelola galeri', function () {
    $album = GaleriAlbum::factory()->create();

    foreach ([buatGuru()->user, User::factory()->waliMurid()->create()] as $user) {
        $this->actingAs($user)->getJson('/api/v1/galeri-album')->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/v1/galeri-album/{$album->id}")->assertForbidden();
    }
});
