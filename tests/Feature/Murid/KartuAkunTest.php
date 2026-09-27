<?php

use App\Enums\Hubungan;
use App\Enums\StatusAkun;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\TahunAjaran;
use App\Services\KartuAkunService;
use App\Services\WaliMuridService;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
    $this->murid = Murid::factory()->create(['nis' => 'TA20260031', 'nama_lengkap' => 'Kirana Ayu Lestari', 'tanggal_lahir' => '2022-03-09']);
    $this->akun = app(WaliMuridService::class)->buatAkunOtomatis($this->murid, Hubungan::Wali, null, $this->kepsek);
    config(['app.frontend_url' => 'https://tkta8.test']);
});

it('mengunduh kartu akun wali murid sebagai PDF', function () {
    $response = $this->actingAs($this->kepsek)->get("/api/v1/murid/{$this->murid->id}/kartu-akun")->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('kartu-akun-TA20260031.pdf')
        ->and(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
});

it('memuat nama anak, kelas, NIS, keterangan password awal, dan alamat website tanpa menulis password', function () {
    $kelas = Kelas::factory()->for(TahunAjaran::factory()->aktif())->create(['nama' => 'TK A2']);
    $this->murid->kelas()->attach($kelas);

    $this->view('pdf.kartu-akun', app(KartuAkunService::class)->isi($this->murid))
        ->assertSee('Kirana Ayu Lestari')
        ->assertSee('TK A2')
        ->assertSee('TA20260031')
        ->assertSee('Password awal: tanggal lahir anak (DDMMYYYY), wajib diganti saat login pertama.')
        ->assertSee('https://tkta8.test/login')
        ->assertDontSee('09032022')
        ->assertDontSee('2022-03-09');
});

it('menolak kartu akun kalau akun wali dengan NIS itu sudah nonaktif', function () {
    $this->akun->user->update(['status' => StatusAkun::Nonaktif]);

    $this->actingAs($this->kepsek)->getJson("/api/v1/murid/{$this->murid->id}/kartu-akun")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
});

it('hanya Kepala Sekolah yang bisa mengunduh kartu akun', function () {
    $this->actingAs(buatGuru()->user)->getJson("/api/v1/murid/{$this->murid->id}/kartu-akun")->assertForbidden();
    $this->actingAs($this->kepsek)->getJson('/api/v1/murid/999999/kartu-akun')->assertNotFound();
});
