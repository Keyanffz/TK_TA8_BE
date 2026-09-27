<?php

use App\Enums\Hubungan;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use Illuminate\Support\Facades\Storage;

it('melengkapi profil wali saat onboarding', function () {
    $wali = WaliMurid::factory()->profilBelumLengkap()->create();

    $this->actingAs($wali->user)->putJson('/api/v1/wali/profil', [
        'no_hp' => '082134567890',
        'alamat' => 'Jl. Pandanaran No. 12, Semarang Tengah',
        'pekerjaan' => 'Wiraswasta',
        'nik' => '3374011203880002',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Profil tersimpan.')
        ->assertJsonPath('data.no_hp', '082134567890')
        ->assertJsonPath('data.wali_murid.profil_lengkap', true);

    $wali->refresh();
    expect($wali->alamat)->toBe('Jl. Pandanaran No. 12, Semarang Tengah')
        ->and($wali->pekerjaan)->toBe('Wiraswasta')
        ->and($wali->nik)->toBe('3374011203880002');
});

it('membolehkan NIK dikosongkan saat onboarding', function () {
    $wali = WaliMurid::factory()->profilBelumLengkap()->create();

    $this->actingAs($wali->user)->putJson('/api/v1/wali/profil', [
        'no_hp' => '082134567890',
        'alamat' => 'Jl. Pandanaran No. 12, Semarang Tengah',
        'pekerjaan' => 'Wiraswasta',
    ])->assertOk();

    expect($wali->fresh()?->nik)->toBeNull();
});

it('menyimpan profil sebagian dan baru menandai lengkap setelah nomor HP, alamat, dan pekerjaan terisi', function () {
    $wali = WaliMurid::factory()->profilBelumLengkap()->create();

    $this->actingAs($wali->user)->putJson('/api/v1/wali/profil', ['alamat' => 'Jl. Pandanaran No. 12, Semarang Tengah'])
        ->assertOk()
        ->assertJsonPath('data.wali_murid.alamat', 'Jl. Pandanaran No. 12, Semarang Tengah')
        ->assertJsonPath('data.wali_murid.profil_lengkap', false);

    $this->putJson('/api/v1/wali/profil', ['no_hp' => '082134567890', 'pekerjaan' => 'Perawat'])
        ->assertOk()
        ->assertJsonPath('data.wali_murid.pekerjaan', 'Perawat')
        ->assertJsonPath('data.wali_murid.alamat', 'Jl. Pandanaran No. 12, Semarang Tengah')
        ->assertJsonPath('data.wali_murid.profil_lengkap', true);
});

it('mengubah sebagian profil wali yang sudah lengkap tanpa mengosongkan field lain', function () {
    $wali = WaliMurid::factory()->create(['pekerjaan' => 'Guru', 'nik' => '3374011203880002']);

    $this->actingAs($wali->user)->putJson('/api/v1/wali/profil', ['pekerjaan' => 'Wiraswasta', 'nik' => null])
        ->assertOk()
        ->assertJsonPath('data.wali_murid.pekerjaan', 'Wiraswasta')
        ->assertJsonPath('data.wali_murid.nik', null)
        ->assertJsonPath('data.wali_murid.alamat', $wali->alamat)
        ->assertJsonPath('data.wali_murid.profil_lengkap', true);
});

it('menolak mengosongkan nomor HP, alamat, atau pekerjaan dan NIK yang tidak 16 digit', function () {
    $wali = WaliMurid::factory()->create();

    $this->actingAs($wali->user)->putJson('/api/v1/wali/profil', ['no_hp' => '', 'alamat' => '', 'pekerjaan' => null, 'nik' => '123'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['no_hp', 'alamat', 'pekerjaan', 'nik']);

    expect($wali->fresh()?->profil_lengkap)->toBeTrue();
});

it('menampilkan NIK, alamat, dan pekerjaan wali di /auth/me', function () {
    $wali = WaliMurid::factory()->create(['nik' => '3374011203880002', 'alamat' => 'Jl. Pandanaran No. 12', 'pekerjaan' => 'Perawat']);

    $this->actingAs($wali->user)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.wali_murid.nik', '3374011203880002')
        ->assertJsonPath('data.wali_murid.alamat', 'Jl. Pandanaran No. 12')
        ->assertJsonPath('data.wali_murid.pekerjaan', 'Perawat');
});

it('menampilkan hanya anak milik wali yang login', function () {
    Storage::fake('local');
    $wali = WaliMurid::factory()->create();
    $kelas = Kelas::factory()->for(TahunAjaran::factory()->aktif())->create(['nama' => 'TK B1']);
    $anak = Murid::factory()->create(['nama_panggilan' => 'Bima', 'foto_path' => 'murid/bima.jpg']);
    $anak->kelas()->attach($kelas);
    $wali->murid()->attach($anak, ['hubungan' => Hubungan::Ayah, 'is_kontak_utama' => false]);
    Murid::factory()->create();

    $response = $this->actingAs($wali->user)->getJson('/api/v1/wali/anak')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $anak->id)
        ->assertJsonPath('data.0.nama_panggilan', 'Bima')
        ->assertJsonPath('data.0.kelas', ['id' => $kelas->id, 'nama' => 'TK B1'])
        ->assertJsonPath('data.0.hubungan', 'ayah')
        ->assertJsonPath('data.0.is_kontak_utama', false);

    expect($response->json('data.0.foto_url'))->toContain('/api/v1/media/')->toContain('signature=');
});

it('mengembalikan daftar kosong untuk wali yang belum menautkan anak', function () {
    $wali = WaliMurid::factory()->create();

    $this->actingAs($wali->user)->getJson('/api/v1/wali/anak')
        ->assertOk()
        ->assertJsonPath('data', []);
});
