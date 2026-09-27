<?php

use App\Enums\Hubungan;
use App\Enums\StatusAkun;
use App\Enums\StatusMurid;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use App\Notifications\AnakTertautNotification;
use App\Services\WaliMuridService;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    $this->kepsek = buatKepalaSekolah();
    $this->wali = WaliMurid::factory()->create();
    $this->wali->murid()->attach(Murid::factory()->create(), ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $this->adik = Murid::factory()->create(['nis' => 'TA20260031', 'nama_panggilan' => 'Kirana', 'tanggal_lahir' => '2022-03-09']);
    $this->akunAdik = app(WaliMuridService::class)->buatAkunOtomatis($this->adik, Hubungan::Wali, null, $this->kepsek);
});

function tambahAnak(object $test, WaliMurid $wali, array $timpa = []): object
{
    return $test->actingAs($wali->user)->postJson('/api/v1/wali/tambah-anak', [
        'nis' => 'TA20260031',
        'tanggal_lahir' => '2022-03-09',
        'hubungan' => 'ibu',
        ...$timpa,
    ]);
}

it('menautkan adik dan menonaktifkan akun otomatisnya yang belum dipakai', function () {
    $kelas = Kelas::factory()->for(TahunAjaran::factory()->aktif())->create(['nama' => 'TK A2']);
    $this->adik->kelas()->attach($kelas);
    $this->akunAdik->user->createToken('web');

    tambahAnak($this, $this->wali)
        ->assertOk()
        ->assertJsonPath('message', 'Kirana berhasil ditambahkan ke akun Anda.')
        ->assertJsonPath('data.id', $this->adik->id)
        ->assertJsonPath('data.kelas', ['id' => $kelas->id, 'nama' => 'TK A2'])
        ->assertJsonPath('data.hubungan', 'ibu')
        ->assertJsonPath('data.is_kontak_utama', true);

    expect($this->adik->waliMurid()->pluck('wali_murid.id')->all())->toBe([$this->wali->id])
        ->and($this->akunAdik->user->fresh()?->status)->toBe(StatusAkun::Nonaktif)
        ->and($this->akunAdik->user->tokens()->count())->toBe(0);

    $log = Activity::query()->where('log_name', 'wali')->sole();
    expect($log->event)->toBe('tertaut')
        ->and($log->causer_id)->toBe($this->wali->user_id)
        ->and($log->subject_id)->toBe($this->adik->id)
        ->and(Activity::query()->where('log_name', 'akun')->where('event', 'dinonaktifkan')->where('subject_id', $this->akunAdik->user_id)->exists())->toBeTrue();

    Notification::assertSentTo($this->kepsek, AnakTertautNotification::class);
    Notification::assertNotSentTo($this->akunAdik->user, AnakTertautNotification::class);

    $this->getJson('/api/v1/auth/me')->assertJsonCount(2, 'data.wali_murid.anak');
});

it('tetap menautkan akun lain kalau akun otomatis anak itu sudah dipakai', function () {
    $this->akunAdik->user->update(['wajib_ganti_password' => false, 'password' => 'ayahKirana22']);

    tambahAnak($this, $this->wali)
        ->assertOk()
        ->assertJsonPath('data.is_kontak_utama', false);

    expect($this->adik->waliMurid()->count())->toBe(2)
        ->and($this->akunAdik->user->fresh()?->status)->toBe(StatusAkun::Aktif);

    Notification::assertSentTo($this->akunAdik->user, AnakTertautNotification::class);
});

it('tidak menonaktifkan akun otomatis yang juga tertaut ke anak lain', function () {
    $this->akunAdik->murid()->attach(Murid::factory()->create(), ['hubungan' => Hubungan::Ayah, 'is_kontak_utama' => true]);

    tambahAnak($this, $this->wali)->assertOk();

    expect($this->akunAdik->user->fresh()?->status)->toBe(StatusAkun::Aktif)
        ->and($this->adik->waliMurid()->count())->toBe(2);
});

it('menerima NIS yang diketik dengan huruf kecil atau spasi', function () {
    tambahAnak($this, $this->wali, ['nis' => ' ta2026 0031 '])->assertOk();
});

it('menolak NIS atau tanggal lahir yang tidak cocok dengan pesan yang sama', function (array $timpa) {
    tambahAnak($this, $this->wali, $timpa)
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.nis', ['NIS atau tanggal lahir anak tidak cocok. Periksa kembali NIS di kartu akun dari sekolah.']);

    expect($this->akunAdik->user->fresh()?->status)->toBe(StatusAkun::Aktif);
})->with([
    'NIS tidak terdaftar' => [['nis' => 'TA20269999']],
    'tanggal lahir salah' => [['tanggal_lahir' => '2022-03-10']],
]);

it('menolak anak yang sudah tertaut ke akun yang sama', function () {
    tambahAnak($this, $this->wali)->assertOk();

    tambahAnak($this, $this->wali)
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Kirana sudah tertaut ke akun Anda.');
});

it('menolak anak yang tidak lagi aktif di sekolah', function () {
    $this->adik->update(['status' => StatusMurid::Pindah, 'tanggal_keluar' => '2026-09-01']);

    tambahAnak($this, $this->wali)
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
});

it('memvalidasi isian tambah anak', function (array $timpa, string $field) {
    tambahAnak($this, $this->wali, $timpa)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'NIS kosong' => [['nis' => ''], 'nis'],
    'format tanggal salah' => [['tanggal_lahir' => '09-03-2022'], 'tanggal_lahir'],
    'hubungan asing' => [['hubungan' => 'kakek'], 'hubungan'],
]);

it('membatasi 5 percobaan tambah anak per menit per akun wali', function () {
    foreach (range(1, 5) as $_) {
        tambahAnak($this, $this->wali, ['nis' => 'TA20269999'])->assertStatus(422);
    }

    tambahAnak($this, $this->wali)
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');

    tambahAnak($this, WaliMurid::factory()->create(), ['nis' => 'TA20269999'])->assertStatus(422);
});

it('hanya tersedia untuk wali murid yang sudah mengganti password awal', function () {
    $this->actingAs(buatGuru()->user)->postJson('/api/v1/wali/tambah-anak', [])->assertForbidden();

    $this->wali->user->update(['wajib_ganti_password' => true]);
    tambahAnak($this, $this->wali)
        ->assertForbidden()
        ->assertJsonPath('code', 'PASSWORD_WAJIB_DIGANTI');
});
