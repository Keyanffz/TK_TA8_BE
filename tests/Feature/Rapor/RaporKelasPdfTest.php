<?php

use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use App\Enums\StatusRapor;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Rapor;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\RaporPdfService;
use Illuminate\Support\Facades\Storage;

/**
 * TK A1 (wali kelas Bu Aini, pendamping Bu Rina): rapor semester 1 Zahra dan Aisyah terbit, Bima masih diajukan;
 * semester 2 belum ada yang terbit. Bu Sri wali kelas TK B1 dan berizin data murid.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->kepsek = buatKepalaSekolah();
    $tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->buAini = buatGuru();
    $this->buRina = buatGuru();
    $this->buSri = buatGuru();
    $this->buSri->izin()->create(['modul' => ModulIzin::DataMurid, 'level' => LevelIzin::Kelola]);
    $this->kelas = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id, 'guru_pendamping_id' => $this->buRina->id]);
    Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK B1', 'wali_kelas_id' => $this->buSri->id]);

    $buat = function (string $nama, StatusRapor $status): Rapor {
        $murid = Murid::factory()->create(['nama_lengkap' => $nama]);
        $this->kelas->murid()->attach($murid);

        return Rapor::factory()->for($murid)->for($this->kelas)->create(['status' => $status, 'dibuat_oleh' => $this->buAini->id, 'terbit_at' => now()]);
    };
    $buat('Zahra Aulia', StatusRapor::Terbit);
    $buat('Aisyah Putri', StatusRapor::Terbit);
    $buat('Bima Saputra', StatusRapor::Diajukan);
});

it('mengunduh satu PDF rapor terbit satu kelas untuk wali kelas, pendamping, dan Kepala Sekolah', function (Closure $siapa) {
    $respons = $this->actingAs($siapa($this))->get("/api/v1/kelas/{$this->kelas->id}/rapor/pdf?semester=1")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($respons->headers->get('content-disposition'))->toContain('rapor-tk-a1-2026-2027-semester-1.pdf')
        ->and(substr((string) $respons->getContent(), 0, 4))->toBe('%PDF');
})->with([
    'wali kelas' => [fn ($test) => $test->buAini->user],
    'guru pendamping' => [fn ($test) => $test->buRina->user],
    'Kepala Sekolah' => [fn ($test) => $test->kepsek],
]);

it('hanya memuat rapor terbit, urut nama, masing-masing di halaman baru', function () {
    $html = view('pdf.rapor-kelas', app(RaporPdfService::class)->isiKelas($this->kelas, 1))->render();

    expect($html)->not->toContain('Bima Saputra')
        ->and(strpos($html, 'Aisyah Putri'))->toBeLessThan(strpos($html, 'Zahra Aulia'))
        ->and(substr_count($html, 'class="rapor-berikutnya"'))->toBe(1);
});

it('menolak semester yang belum punya rapor terbit', function () {
    $this->actingAs($this->buAini->user)->getJson("/api/v1/kelas/{$this->kelas->id}/rapor/pdf?semester=2")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Belum ada rapor terbit di TK A1 semester 2. Terbitkan rapor dulu, lalu unduh lagi.');
});

it('menolak guru kelas lain walau berizin data murid, wali murid, dan semester yang salah', function () {
    $this->actingAs($this->buSri->user)->getJson("/api/v1/kelas/{$this->kelas->id}/rapor/pdf?semester=1")->assertNotFound();
    $this->actingAs(User::factory()->waliMurid()->create())->getJson("/api/v1/kelas/{$this->kelas->id}/rapor/pdf?semester=1")->assertForbidden();
    $this->actingAs($this->buAini->user)->getJson("/api/v1/kelas/{$this->kelas->id}/rapor/pdf?semester=3")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['semester']);
});
