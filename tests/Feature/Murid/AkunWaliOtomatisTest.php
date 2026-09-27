<?php

use App\Enums\Hubungan;
use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Models\Murid;
use App\Models\User;
use App\Models\WaliMurid;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
});

function dataMuridBaru(array $timpa = []): array
{
    return [
        'nama_lengkap' => 'Raka Aditya Pratama',
        'nama_panggilan' => 'Raka',
        'jenis_kelamin' => 'L',
        'tempat_lahir' => 'Semarang',
        'tanggal_lahir' => '2021-11-05',
        'agama' => 'Islam',
        'alamat' => 'Jl. Pedurungan Kidul No. 8, Semarang',
        'tanggal_masuk' => '2026-07-13',
        ...$timpa,
    ];
}

it('membuat akun wali dengan username NIS dan password tanggal lahir saat murid ditambahkan', function () {
    $response = $this->actingAs($this->kepsek)->postJson('/api/v1/murid', dataMuridBaru())
        ->assertCreated()
        ->assertJsonPath('data.wali.0.nama', 'Wali Raka')
        ->assertJsonPath('data.wali.0.email', null)
        ->assertJsonPath('data.wali.0.hubungan', 'wali')
        ->assertJsonPath('data.wali.0.is_kontak_utama', true);

    $nis = $response->json('data.nis');
    $user = User::query()->where('username', $nis)->sole();

    expect($user->role)->toBe(Role::WaliMurid)
        ->and($user->status)->toBe(StatusAkun::Aktif)
        ->and($user->email)->toBeNull()
        ->and($user->wajib_ganti_password)->toBeTrue()
        ->and(Hash::check('05112021', (string) $user->password))->toBeTrue()
        ->and($user->waliMurid?->profil_lengkap)->toBeFalse();

    $log = Activity::query()->where('log_name', 'akun')->where('event', 'dibuat')->sole();
    expect($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->subject_id)->toBe($user->id)
        ->and($log->properties->all())->toBe(['murid_id' => $response->json('data.id'), 'username' => $nis]);

    $this->postJson('/api/v1/auth/login-wali', ['username' => $nis, 'password' => '05112021'])
        ->assertOk()
        ->assertJsonPath('data.user.wajib_ganti_password', true)
        ->assertJsonPath('data.user.wali_murid.anak.0.nama_panggilan', 'Raka');
});

it('membatalkan pembuatan murid kalau akun walinya gagal dibuat', function () {
    User::factory()->waliMurid()->create(['username' => 'TA20260001']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/murid', dataMuridBaru())->assertServerError();

    expect(Murid::query()->count())->toBe(0);
});

it('menonaktifkan akun wali otomatis yang belum dipakai saat murid dihapus', function () {
    $nis = $this->actingAs($this->kepsek)->postJson('/api/v1/murid', dataMuridBaru())->json('data.nis');
    $akun = User::query()->where('username', $nis)->sole();
    $akun->createToken('web');
    $murid = Murid::query()->where('nis', $nis)->sole();

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/murid/{$murid->id}")->assertOk();

    expect($akun->fresh()?->status)->toBe(StatusAkun::Nonaktif)
        ->and($akun->tokens()->count())->toBe(0)
        ->and($akun->waliMurid?->murid()->count())->toBe(0)
        ->and(Activity::query()->where('log_name', 'akun')->where('event', 'dinonaktifkan')->where('subject_id', $akun->id)->exists())->toBeTrue();
});

it('tidak menyentuh akun wali yang sudah dipakai saat murid dihapus', function () {
    $murid = Murid::factory()->create(['nis' => 'TA20260005']);
    $wali = WaliMurid::factory()->for(User::factory()->waliMurid()->state(['username' => 'TA20260005']))->create();
    $murid->waliMurid()->attach($wali, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/murid/{$murid->id}")->assertOk();

    expect($wali->user->fresh()?->status)->toBe(StatusAkun::Aktif);
});

function ubahTanggalLahir(object $test, User $kepsek, Murid $murid, string $tanggalLahir): void
{
    $test->actingAs($kepsek)->putJson("/api/v1/murid/{$murid->id}", [
        ...dataMuridBaru(['tanggal_lahir' => $tanggalLahir]),
        'status' => 'aktif',
    ])->assertOk();
}

it('mengganti password awal akun otomatis yang belum dipakai saat tanggal lahir murid dikoreksi', function () {
    $nis = $this->actingAs($this->kepsek)->postJson('/api/v1/murid', dataMuridBaru())->json('data.nis');
    $murid = Murid::query()->where('nis', $nis)->sole();
    $akun = User::query()->where('username', $nis)->sole();

    ubahTanggalLahir($this, $this->kepsek, $murid, '2021-12-06');

    $akun->refresh();
    expect(Hash::check('06122021', (string) $akun->password))->toBeTrue()
        ->and(Hash::check('05112021', (string) $akun->password))->toBeFalse()
        ->and($akun->wajib_ganti_password)->toBeTrue();

    $log = Activity::query()->where('log_name', 'akun')->where('event', 'password_disesuaikan')->sole();
    expect($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->subject_id)->toBe($akun->id)
        ->and($log->properties->all())->toBe(['murid_id' => $murid->id]);

    $this->postJson('/api/v1/auth/login-wali', ['username' => $nis, 'password' => '06122021'])->assertOk();
});

it('tidak mengubah password akun yang sudah dipakai saat tanggal lahir murid dikoreksi', function () {
    $nis = $this->actingAs($this->kepsek)->postJson('/api/v1/murid', dataMuridBaru())->json('data.nis');
    $murid = Murid::query()->where('nis', $nis)->sole();
    $akun = User::query()->where('username', $nis)->sole();
    $akun->update(['password' => 'rakaCeria21', 'wajib_ganti_password' => false]);

    ubahTanggalLahir($this, $this->kepsek, $murid, '2021-12-06');

    expect(Hash::check('rakaCeria21', (string) $akun->fresh()?->password))->toBeTrue();
});

it('tidak mengubah password akun keluarga yang direset dan tertaut ke anak lain', function () {
    $nis = $this->actingAs($this->kepsek)->postJson('/api/v1/murid', dataMuridBaru())->json('data.nis');
    $murid = Murid::query()->where('nis', $nis)->sole();
    $akun = User::query()->with('waliMurid')->where('username', $nis)->sole();
    $akun->waliMurid?->murid()->attach(Murid::factory()->create(), ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    ubahTanggalLahir($this, $this->kepsek, $murid, '2021-12-06');

    expect(Hash::check('05112021', (string) $akun->fresh()?->password))->toBeTrue()
        ->and(Activity::query()->where('event', 'password_disesuaikan')->exists())->toBeFalse();
});

it('tidak menyentuh password akun kalau tanggal lahir tidak berubah', function () {
    $nis = $this->actingAs($this->kepsek)->postJson('/api/v1/murid', dataMuridBaru())->json('data.nis');
    $murid = Murid::query()->where('nis', $nis)->sole();

    ubahTanggalLahir($this, $this->kepsek, $murid, '2021-11-05');

    expect(Activity::query()->where('event', 'password_disesuaikan')->exists())->toBeFalse();
});
