<?php

use App\Enums\Hubungan;
use App\Models\KegiatanFoto;
use App\Models\KegiatanKelas;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * TK A1 diampu Bu Aini (wali kelas) dan Bu Rina (pendamping), TK A2 diampu Bu Dwi. Aisyah di TK A1,
 * tertaut ke ibunya.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    Storage::fake('local');

    $this->kepsek = buatKepalaSekolah();
    $this->tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->buAini = buatGuru();
    $this->buRina = buatGuru();
    $this->buDwi = buatGuru();
    $this->kelasA1 = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id, 'guru_pendamping_id' => $this->buRina->id]);
    $this->kelasA2 = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK A2', 'wali_kelas_id' => $this->buDwi->id]);

    $this->aisyah = Murid::factory()->create();
    $this->kelasA1->murid()->attach($this->aisyah);
    $this->ibu = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($this->ibu, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
});

function dataKegiatan(int $kelasId, array $timpa = []): array
{
    return [
        'kelas_id' => $kelasId,
        'tanggal' => '2026-10-05',
        'tema' => 'Tanaman',
        'judul' => 'Menanam biji kacang hijau',
        'deskripsi' => 'Anak-anak menanam biji kacang hijau di gelas plastik bekas.',
        'foto' => [UploadedFile::fake()->image('a.jpg', 2400, 1800), UploadedFile::fake()->image('b.png', 800, 600)],
        ...$timpa,
    ];
}

it('mencatat kegiatan beserta foto oleh wali kelas', function () {
    $response = $this->actingAs($this->buAini->user)->post('/api/v1/kegiatan', dataKegiatan($this->kelasA1->id))
        ->assertCreated()
        ->assertJsonPath('message', 'Kegiatan Menanam biji kacang hijau tersimpan.')
        ->assertJsonPath('data.kelas', ['id' => $this->kelasA1->id, 'nama' => 'TK A1'])
        ->assertJsonPath('data.guru.id', $this->buAini->id)
        ->assertJsonPath('data.tanggal', '2026-10-05')
        ->assertJsonCount(2, 'data.foto')
        ->assertJsonPath('data.foto.0.urutan', 1)
        ->assertJsonPath('data.foto.1.urutan', 2);

    expect($response->json('data.foto.0.url'))->toContain('/api/v1/media/');
    $foto = KegiatanFoto::query()->orderBy('urutan')->get();
    expect($foto)->toHaveCount(2)
        ->and($foto[0]->path)->toStartWith('kegiatan/');
    Storage::disk('local')->assertExists($foto->pluck('path')->all());
    expect(getimagesizefromstring((string) Storage::disk('local')->get($foto[0]->path))[0])->toBe(1600);
});

it('mencatat kegiatan tanpa foto, dan Kepala Sekolah memakai profil gurunya sebagai pembuat', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/kegiatan', dataKegiatan($this->kelasA2->id, ['foto' => []]))
        ->assertCreated()
        ->assertJsonPath('data.guru.id', $this->kepsek->guru->id)
        ->assertJsonPath('data.foto', []);
});

it('menolak guru mencatat kegiatan untuk kelas yang tidak dia ampu', function () {
    $this->actingAs($this->buAini->user)->post('/api/v1/kegiatan', dataKegiatan($this->kelasA2->id))
        ->assertStatus(422)
        ->assertJsonPath('errors.kelas_id.0', 'Anda hanya bisa mencatat kegiatan untuk kelas yang Anda ampu.');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('menolak lebih dari 10 foto sekali unggah dan tanggal di masa depan', function () {
    $foto = array_map(fn () => UploadedFile::fake()->image('x.jpg'), range(1, 11));

    $this->actingAs($this->buAini->user)->post('/api/v1/kegiatan', dataKegiatan($this->kelasA1->id, ['foto' => $foto, 'tanggal' => '2026-10-06']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['foto', 'tanggal']);
});

it('menampilkan kegiatan sesuai jangkauan tiap role', function () {
    $kegiatanA1 = KegiatanKelas::factory()->for($this->kelasA1)->create(['guru_id' => $this->buAini->id, 'tanggal' => '2026-10-01']);
    $kegiatanA2 = KegiatanKelas::factory()->for($this->kelasA2)->create(['guru_id' => $this->buDwi->id, 'tanggal' => '2026-10-02']);
    KegiatanFoto::factory()->for($kegiatanA1, 'kegiatan')->create();

    $this->actingAs($this->kepsek)->getJson('/api/v1/kegiatan')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.id', $kegiatanA2->id);
    $this->actingAs($this->kepsek)->getJson("/api/v1/kegiatan?filter[kelas_id]={$this->kelasA1->id}")->assertJsonPath('meta.total', 1);

    $this->actingAs($this->buRina->user)->getJson('/api/v1/kegiatan')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $kegiatanA1->id)
        ->assertJsonCount(1, 'data.0.foto');

    $this->actingAs($this->ibu->user)->getJson('/api/v1/kegiatan')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $kegiatanA1->id);
    $this->actingAs($this->ibu->user)->getJson("/api/v1/kegiatan/{$kegiatanA1->id}")->assertOk();
    $this->actingAs($this->ibu->user)->getJson("/api/v1/kegiatan/{$kegiatanA2->id}")->assertNotFound();
    $this->actingAs($this->buAini->user)->getJson("/api/v1/kegiatan/{$kegiatanA2->id}")->assertNotFound();
});

it('hanya guru pembuat dan Kepala Sekolah yang bisa mengubah dan menghapus kegiatan', function () {
    $kegiatan = KegiatanKelas::factory()->for($this->kelasA1)->create(['guru_id' => $this->buAini->id]);
    $data = ['tanggal' => '2026-10-04', 'tema' => 'Tanaman', 'judul' => 'Mengamati kecambah'];

    $this->actingAs($this->buRina->user)->putJson("/api/v1/kegiatan/{$kegiatan->id}", $data)->assertForbidden();
    $this->actingAs($this->buDwi->user)->putJson("/api/v1/kegiatan/{$kegiatan->id}", $data)->assertNotFound();
    $this->actingAs($this->ibu->user)->putJson("/api/v1/kegiatan/{$kegiatan->id}", $data)->assertForbidden();

    $this->actingAs($this->buAini->user)->putJson("/api/v1/kegiatan/{$kegiatan->id}", $data)
        ->assertOk()
        ->assertJsonPath('data.judul', 'Mengamati kecambah')
        ->assertJsonPath('data.tanggal', '2026-10-04');
    $this->actingAs($this->kepsek)->putJson("/api/v1/kegiatan/{$kegiatan->id}", $data)->assertOk();
});

it('menolak mengganti kelas kegiatan lewat PUT', function () {
    $kegiatan = KegiatanKelas::factory()->for($this->kelasA1)->create(['guru_id' => $this->buAini->id]);

    $this->actingAs($this->buAini->user)->putJson("/api/v1/kegiatan/{$kegiatan->id}", ['tanggal' => '2026-10-04', 'judul' => 'Kecambah', 'kelas_id' => $this->kelasA2->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['kelas_id']);
});

it('menghapus kegiatan beserta file fotonya', function () {
    $id = $this->actingAs($this->buAini->user)->post('/api/v1/kegiatan', dataKegiatan($this->kelasA1->id))->json('data.id');

    $this->actingAs($this->buAini->user)->deleteJson("/api/v1/kegiatan/{$id}")->assertOk();

    expect(KegiatanKelas::query()->count())->toBe(0)
        ->and(KegiatanFoto::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('menambah foto dengan urutan lanjutan dan menghapus satu foto', function () {
    $id = $this->actingAs($this->buAini->user)->post('/api/v1/kegiatan', dataKegiatan($this->kelasA1->id))->json('data.id');

    $this->actingAs($this->buAini->user)->post("/api/v1/kegiatan/{$id}/foto", ['foto' => [UploadedFile::fake()->image('c.jpg')]])
        ->assertCreated()
        ->assertJsonPath('message', '1 foto ditambahkan.')
        ->assertJsonCount(3, 'data.foto')
        ->assertJsonPath('data.foto.2.urutan', 3);

    $foto = KegiatanFoto::query()->where('urutan', 1)->sole();
    $this->actingAs($this->buRina->user)->deleteJson("/api/v1/kegiatan-foto/{$foto->id}")->assertForbidden();
    $this->actingAs($this->buAini->user)->deleteJson("/api/v1/kegiatan-foto/{$foto->id}")->assertOk();

    Storage::disk('local')->assertMissing($foto->path);
    expect(KegiatanFoto::query()->count())->toBe(2);
});

it('menolak foto yang melewati batas 30 per kegiatan tanpa meninggalkan file', function () {
    $kegiatan = KegiatanKelas::factory()->for($this->kelasA1)->create(['guru_id' => $this->buAini->id]);
    KegiatanFoto::factory()->count(25)->for($kegiatan, 'kegiatan')->create();
    $foto = array_map(fn () => UploadedFile::fake()->image('x.jpg'), range(1, 6));

    $this->actingAs($this->buAini->user)->post("/api/v1/kegiatan/{$kegiatan->id}/foto", ['foto' => $foto])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Satu kegiatan paling banyak berisi 30 foto. Kegiatan ini masih bisa ditambah 5 foto.');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('menolak wali murid mencatat kegiatan', function () {
    $this->actingAs($this->ibu->user)->post('/api/v1/kegiatan', dataKegiatan($this->kelasA1->id))->assertForbidden();
});
