<?php

use App\Enums\Hubungan;
use App\Enums\StatusAkun;
use App\Models\Murid;
use App\Models\User;
use App\Models\WaliMurid;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
    $this->kakak = Murid::factory()->create(['nis' => 'TA20250004', 'nama_panggilan' => 'Bima', 'tanggal_lahir' => '2020-12-01']);
    $this->adik = Murid::factory()->create(['nis' => 'TA20260012', 'nama_panggilan' => 'Kirana', 'tanggal_lahir' => '2022-03-09']);
    $this->wali = WaliMurid::factory()->for(User::factory()->waliMurid()->state(['name' => 'Dewi Lestari', 'username' => 'TA20260012', 'password' => 'dewiBaru2026']))->create();
    $this->wali->murid()->attach($this->kakak, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $this->wali->murid()->attach($this->adik, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
});

it('mengembalikan password ke tanggal lahir anak yang NIS-nya menjadi username dan mencabut semua sesi', function () {
    $this->wali->user->createToken('web');
    $this->wali->user->createToken('mobile');

    $this->actingAs($this->kepsek)->postJson("/api/v1/wali-murid/{$this->wali->id}/reset-password")
        ->assertOk()
        ->assertJsonPath('message', 'Password Dewi Lestari dikembalikan ke tanggal lahir Kirana (DDMMYYYY) dan wajib diganti saat login berikutnya.')
        ->assertJsonPath('data.user.username', 'TA20260012')
        ->assertJsonPath('data.user.wajib_ganti_password', true)
        ->assertJsonPath('data.jumlah_anak', 2);

    $user = $this->wali->user->fresh();
    expect(Hash::check('09032022', (string) $user?->password))->toBeTrue()
        ->and($user?->wajib_ganti_password)->toBeTrue()
        ->and($user?->tokens()->count())->toBe(0);

    $log = Activity::query()->where('log_name', 'akun')->where('event', 'password_direset')->sole();
    expect($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->subject_id)->toBe($this->wali->user_id)
        ->and($log->properties->all())->toBe(['murid_id' => $this->adik->id]);
});

it('memakai anak kontak utama yang paling awal tertaut kalau username bukan NIS salah satunya', function () {
    $this->wali->user->update(['username' => 'TA20240001']);

    $this->actingAs($this->kepsek)->postJson("/api/v1/wali-murid/{$this->wali->id}/reset-password")->assertOk();

    expect(Hash::check('01122020', (string) $this->wali->user->fresh()?->password))->toBeTrue();
});

it('menolak reset password wali yang bukan kontak utama anak mana pun', function () {
    $this->wali->murid()->updateExistingPivot($this->kakak->id, ['is_kontak_utama' => false]);
    $this->wali->murid()->updateExistingPivot($this->adik->id, ['is_kontak_utama' => false]);

    $this->actingAs($this->kepsek)->postJson("/api/v1/wali-murid/{$this->wali->id}/reset-password")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(Hash::check('dewiBaru2026', (string) $this->wali->user->fresh()?->password))->toBeTrue();
});

it('tidak mengubah status akun wali yang nonaktif', function () {
    $this->wali->user->update(['status' => StatusAkun::Nonaktif]);

    $this->actingAs($this->kepsek)->postJson("/api/v1/wali-murid/{$this->wali->id}/reset-password")->assertOk();

    expect($this->wali->user->fresh()?->status)->toBe(StatusAkun::Nonaktif);
});

it('hanya Kepala Sekolah yang bisa mereset password wali', function () {
    $this->actingAs(buatGuru()->user)->postJson("/api/v1/wali-murid/{$this->wali->id}/reset-password")->assertForbidden();
    $this->actingAs($this->wali->user)->postJson("/api/v1/wali-murid/{$this->wali->id}/reset-password")->assertForbidden();
    $this->actingAs($this->kepsek)->postJson('/api/v1/wali-murid/999999/reset-password')->assertNotFound();
});

it('mencari wali murid lewat username', function () {
    WaliMurid::factory()->create();

    $this->actingAs($this->kepsek)->getJson('/api/v1/wali-murid?search=TA20260012')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->wali->id);
});
