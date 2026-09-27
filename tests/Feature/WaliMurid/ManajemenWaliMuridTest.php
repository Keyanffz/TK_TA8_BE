<?php

use App\Enums\Hubungan;
use App\Enums\StatusAkun;
use App\Models\Murid;
use App\Models\User;
use App\Models\WaliMurid;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
});

it('menampilkan daftar wali murid beserta jumlah anak', function () {
    $wali = WaliMurid::factory()->for(User::factory()->waliMurid()->state(['name' => 'Agus Prasetyo']))->create();
    $wali->murid()->attach(Murid::factory()->count(2)->create(), ['hubungan' => Hubungan::Ayah, 'is_kontak_utama' => true]);
    WaliMurid::factory()->for(User::factory()->waliMurid()->state(['name' => 'Wulan Sari']))->create();

    $this->actingAs($this->kepsek)->getJson('/api/v1/wali-murid')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.user.name', 'Agus Prasetyo')
        ->assertJsonPath('data.0.jumlah_anak', 2)
        ->assertJsonPath('data.1.jumlah_anak', 0)
        ->assertJsonMissingPath('data.0.anak');
});

it('mencari wali murid lewat nomor HP', function () {
    WaliMurid::factory()->for(User::factory()->waliMurid()->state(['no_hp' => '089612340000']))->create();
    WaliMurid::factory()->create();

    $this->actingAs($this->kepsek)->getJson('/api/v1/wali-murid?search=0896123')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user.no_hp', '089612340000');
});

it('menampilkan detail wali murid beserta anaknya', function () {
    $wali = WaliMurid::factory()->create();
    $anak = Murid::factory()->create();
    $wali->murid()->attach($anak, ['hubungan' => Hubungan::Wali, 'is_kontak_utama' => true]);

    $this->actingAs($this->kepsek)->getJson("/api/v1/wali-murid/{$wali->id}")
        ->assertOk()
        ->assertJsonPath('data.jumlah_anak', 1)
        ->assertJsonPath('data.anak.0.id', $anak->id)
        ->assertJsonPath('data.anak.0.hubungan', 'wali');
});

it('menonaktifkan wali murid dan mencabut sesinya', function () {
    $wali = WaliMurid::factory()->create();
    $wali->user->createToken('mobile');

    $this->actingAs($this->kepsek)->patchJson("/api/v1/wali-murid/{$wali->id}/status", ['status' => 'nonaktif'])
        ->assertOk()
        ->assertJsonPath('data.user.status', 'nonaktif');

    expect($wali->user->fresh()?->status)->toBe(StatusAkun::Nonaktif)
        ->and($wali->user->tokens()->count())->toBe(0)
        ->and(Activity::query()->where('log_name', 'akun')->sole()->subject_id)->toBe($wali->user_id);
});

it('mengubah data wali murid oleh Kepala Sekolah dan mencatat field yang berubah', function () {
    $wali = WaliMurid::factory()->for(User::factory()->waliMurid()->state(['name' => 'Agus Prasetya', 'no_hp' => '081234567890']))->create(['pekerjaan' => 'Guru']);
    $emailLama = $wali->user->email;

    $this->actingAs($this->kepsek)->putJson("/api/v1/wali-murid/{$wali->id}", [
        'nama' => 'Agus Prasetyo',
        'no_hp' => '081234567891',
        'nik' => '3374011203880002',
        'alamat' => 'Jl. Majapahit No. 20, Pedurungan',
        'pekerjaan' => 'Guru',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Data Agus Prasetyo tersimpan.')
        ->assertJsonPath('data.user.name', 'Agus Prasetyo')
        ->assertJsonPath('data.user.no_hp', '081234567891')
        ->assertJsonPath('data.user.email', $emailLama)
        ->assertJsonPath('data.nik', '3374011203880002')
        ->assertJsonPath('data.alamat', 'Jl. Majapahit No. 20, Pedurungan')
        ->assertJsonPath('data.jumlah_anak', 0);

    $log = Activity::query()->where('log_name', 'akun')->where('event', 'data_diubah')->sole();
    expect($log->subject_id)->toBe($wali->user_id)
        ->and($log->properties['field'])->toBe(['nama', 'no_hp', 'nik', 'alamat']);
});

it('mengubah sebagian data wali murid dan memperbarui status profil lengkap', function () {
    $wali = WaliMurid::factory()->profilBelumLengkap()->create();

    $this->actingAs($this->kepsek)->putJson("/api/v1/wali-murid/{$wali->id}", ['alamat' => 'Jl. Majapahit No. 20', 'pekerjaan' => 'Pedagang'])
        ->assertOk()
        ->assertJsonPath('data.profil_lengkap', true)
        ->assertJsonPath('data.nik', null);

    $this->putJson("/api/v1/wali-murid/{$wali->id}", ['nama' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('nama');
});

it('menutup perubahan data wali murid untuk guru dan wali', function () {
    $wali = WaliMurid::factory()->create();

    $this->actingAs(buatGuru()->user)->putJson("/api/v1/wali-murid/{$wali->id}", ['nama' => 'Nama Lain'])->assertForbidden();
    $this->actingAs($wali->user)->putJson("/api/v1/wali-murid/{$wali->id}", ['nama' => 'Nama Lain'])->assertForbidden();
});

it('menutup manajemen wali murid untuk guru', function () {
    $this->actingAs(buatGuru()->user)->getJson('/api/v1/wali-murid')->assertForbidden();
});
