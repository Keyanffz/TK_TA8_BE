<?php

use App\Enums\Hubungan;
use App\Enums\StatusRapor;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pengaturan;
use App\Models\Rapor;
use App\Models\RaporDetail;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Aisyah di TK A1 (Bu Aini), Bima di TK B1 (Bu Sri). Ibu Aisyah hanya tertaut ke Aisyah. Aisyah punya rapor
 * semester 1 terbit dan semester 2 draft; Bima punya rapor semester 1 diajukan.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->kepsek = buatKepalaSekolah();
    $tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->buAini = buatGuru();
    $this->buSri = buatGuru();
    $kelasA1 = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id]);
    $kelasB1 = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK B1', 'wali_kelas_id' => $this->buSri->id]);

    $this->aisyah = Murid::factory()->create(['nama_lengkap' => 'Aisyah Putri', 'nis' => 'TA20260001']);
    $bima = Murid::factory()->create();
    $kelasA1->murid()->attach($this->aisyah);
    $kelasB1->murid()->attach($bima);
    $this->ibu = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($this->ibu, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    $this->terbit = Rapor::factory()->for($this->aisyah)->for($kelasA1)->terbit()->create([
        'dibuat_oleh' => $this->buAini->id, 'catatan_revisi' => 'Catatan lama untuk guru.', 'disetujui_oleh' => $this->kepsek->id,
    ]);
    $this->draft = Rapor::factory()->for($this->aisyah)->for($kelasA1)->create(['semester' => 2, 'dibuat_oleh' => $this->buAini->id]);
    $this->diajukanBima = Rapor::factory()->for($bima)->for($kelasB1)->create(['status' => StatusRapor::Diajukan, 'dibuat_oleh' => $this->buSri->id]);
});

it('menampilkan rapor sesuai jangkauan tiap role', function () {
    $this->actingAs($this->kepsek)->getJson('/api/v1/rapor')->assertOk()->assertJsonPath('meta.total', 3);
    $this->actingAs($this->kepsek)->getJson('/api/v1/rapor?filter[status]=diajukan')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->diajukanBima->id);
    $this->actingAs($this->buAini->user)->getJson('/api/v1/rapor')->assertJsonPath('meta.total', 2);
    $this->actingAs($this->buAini->user)->getJson('/api/v1/rapor?filter[semester]=2')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'draft');

    $this->actingAs($this->ibu->user)->getJson('/api/v1/rapor')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $this->terbit->id)
        ->assertJsonMissingPath('data.0.catatan_revisi');
});

it('membalas 404 untuk rapor di luar jangkauan, termasuk rapor anak sendiri yang belum terbit', function () {
    $this->actingAs($this->ibu->user)->getJson("/api/v1/rapor/{$this->draft->id}")->assertNotFound();
    $this->actingAs($this->ibu->user)->getJson("/api/v1/rapor/{$this->diajukanBima->id}")->assertNotFound();
    $this->actingAs($this->ibu->user)->get("/api/v1/rapor/{$this->draft->id}/pdf")->assertNotFound();
    $this->actingAs($this->buAini->user)->getJson("/api/v1/rapor/{$this->diajukanBima->id}")->assertNotFound();
});

it('menampilkan detail rapor terbit ke wali tanpa catatan revisi, dan ke guru dengan catatan revisi', function () {
    RaporDetail::factory()->for($this->terbit)->create(['deskripsi' => 'Aisyah hafal doa sebelum makan.', 'foto_path' => 'rapor/karya.jpg']);

    $this->actingAs($this->ibu->user)->getJson("/api/v1/rapor/{$this->terbit->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.catatan_revisi')
        ->assertJsonPath('data.detail.0.deskripsi', 'Aisyah hafal doa sebelum makan.');

    $this->actingAs($this->buAini->user)->getJson("/api/v1/rapor/{$this->terbit->id}")
        ->assertOk()
        ->assertJsonPath('data.catatan_revisi', 'Catatan lama untuk guru.');
});

it('mengunduh rapor PDF yang sudah terbit untuk wali, dengan nama file rapi', function () {
    Pengaturan::query()->create(['kunci' => 'profil.nama_sekolah', 'nilai' => 'TK Tarbiyathul Athfal 8', 'grup' => 'profil']);
    Storage::disk('local')->put('rapor/karya.jpg', UploadedFile::fake()->image('karya.jpg', 400, 300)->getContent());
    RaporDetail::factory()->for($this->terbit)->create(['deskripsi' => 'Aisyah hafal doa sebelum makan.', 'foto_path' => 'rapor/karya.jpg']);

    $response = $this->actingAs($this->ibu->user)->get("/api/v1/rapor/{$this->terbit->id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))->toContain('rapor-ta20260001-20262027-semester-1.pdf')
        ->and(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
});

it('membolehkan guru dan Kepala Sekolah mengunduh pratinjau PDF rapor yang belum terbit', function () {
    $this->actingAs($this->buAini->user)->get("/api/v1/rapor/{$this->draft->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs($this->kepsek)->get("/api/v1/rapor/{$this->diajukanBima->id}/pdf")->assertOk();
});
