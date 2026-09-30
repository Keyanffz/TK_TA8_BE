<?php

use App\Enums\StatusAkun;
use App\Models\Guru;
use App\Notifications\GuruDisetujuiNotification;
use App\Notifications\GuruDitolakNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    $this->kepsek = buatKepalaSekolah();
});

it('menyetujui guru pending sehingga bisa login', function () {
    $guru = Guru::factory()->menungguPersetujuan()->create();

    $this->actingAs($this->kepsek)->postJson("/api/v1/guru/{$guru->id}/setujui")
        ->assertOk()
        ->assertJsonPath('message', "Pendaftaran {$guru->user->name} disetujui.")
        ->assertJsonPath('data.user.status', 'aktif')
        ->assertJsonPath('data.disetujui_oleh', $this->kepsek->id);

    expect($guru->user->fresh()?->status)->toBe(StatusAkun::Aktif)
        ->and(Activity::query()->where('log_name', 'guru')->sole()->event)->toBe('disetujui');

    Notification::assertSentTo($guru->user, GuruDisetujuiNotification::class, function (GuruDisetujuiNotification $notifikasi) use ($guru): bool {
        return str_starts_with((string) $notifikasi->toMail($guru->user)->actionUrl, 'http://localhost:3000/login');
    });
});

it('menolak guru pending beserta alasan yang terkirim lewat email dan pesan login', function () {
    $guru = Guru::factory()->menungguPersetujuan()->create();

    $this->actingAs($this->kepsek)->postJson("/api/v1/guru/{$guru->id}/tolak", ['alasan' => 'Data NUPTK tidak ditemukan di Dapodik.'])
        ->assertOk()
        ->assertJsonPath('data.user.status', 'ditolak')
        ->assertJsonPath('data.alasan_penolakan', 'Data NUPTK tidak ditemukan di Dapodik.');

    $log = Activity::query()->where('log_name', 'guru')->sole();
    expect($log->event)->toBe('ditolak')
        ->and($log->getProperty('alasan'))->toBe('Data NUPTK tidak ditemukan di Dapodik.');

    Notification::assertSentTo($guru->user, GuruDitolakNotification::class, function (GuruDitolakNotification $notifikasi) use ($guru): bool {
        return in_array('Data NUPTK tidak ditemukan di Dapodik.', $notifikasi->toMail($guru->user)->introLines, true);
    });

    $this->postJson('/api/v1/auth/staff/login', ['email' => $guru->user->email, 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Pendaftaran akun Anda ditolak. Alasan: Data NUPTK tidak ditemukan di Dapodik.');
});

it('mewajibkan alasan penolakan', function () {
    $guru = Guru::factory()->menungguPersetujuan()->create();

    $this->actingAs($this->kepsek)->postJson("/api/v1/guru/{$guru->id}/tolak", ['alasan' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['alasan']);
});

it('tidak memproses ulang pendaftaran yang sudah disetujui atau ditolak', function (StatusAkun $status, string $aksi) {
    $guru = buatGuru($status);

    $this->actingAs($this->kepsek)->postJson("/api/v1/guru/{$guru->id}/{$aksi}", ['alasan' => 'Dobel.'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect($guru->user->fresh()?->status)->toBe($status);
    Notification::assertNothingSent();
})->with([
    'setujui guru aktif' => [StatusAkun::Aktif, 'setujui'],
    'setujui guru ditolak' => [StatusAkun::Ditolak, 'setujui'],
    'tolak guru nonaktif' => [StatusAkun::Nonaktif, 'tolak'],
]);

it('menonaktifkan guru dan langsung mencabut semua sesinya', function () {
    $guru = buatGuru();
    $token = $guru->user->createToken('web')->plainTextToken;
    $guru->user->createToken('mobile');

    $this->actingAs($this->kepsek)->patchJson("/api/v1/guru/{$guru->id}/status", ['status' => 'nonaktif'])
        ->assertOk()
        ->assertJsonPath('message', "Akun {$guru->user->name} sekarang berstatus Nonaktif.")
        ->assertJsonPath('data.user.status', 'nonaktif');

    expect($guru->user->tokens()->count())->toBe(0);

    $log = Activity::query()->where('log_name', 'akun')->sole();
    expect($log->properties->all())->toBe(['dari' => 'aktif', 'ke' => 'nonaktif']);

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('mengaktifkan kembali guru nonaktif', function () {
    $guru = buatGuru(StatusAkun::Nonaktif);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/guru/{$guru->id}/status", ['status' => 'aktif'])
        ->assertOk()
        ->assertJsonPath('data.user.status', 'aktif');
});

it('menolak perubahan status untuk profil Kepala Sekolah dan pendaftaran yang belum diproses', function (Closure $guru, string $pesan) {
    $this->actingAs($this->kepsek)->patchJson("/api/v1/guru/{$guru()->id}/status", ['status' => 'nonaktif'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', $pesan);
})->with([
    'profil Kepala Sekolah' => [fn () => test()->kepsek->guru, 'Profil Kepala Sekolah tidak bisa dinonaktifkan.'],
    'guru pending' => [fn () => buatGuru(StatusAkun::Pending), 'Status hanya bisa diubah untuk guru yang sudah disetujui. Proses pendaftaran lewat Setujui atau Tolak.'],
]);

it('hanya menerima status aktif atau nonaktif', function () {
    $guru = buatGuru();

    $this->actingAs($this->kepsek)->patchJson("/api/v1/guru/{$guru->id}/status", ['status' => 'ditolak'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});
