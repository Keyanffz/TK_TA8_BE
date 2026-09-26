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

it('menutup manajemen wali murid untuk guru', function () {
    $this->actingAs(buatGuru()->user)->getJson('/api/v1/wali-murid')->assertForbidden();
});
