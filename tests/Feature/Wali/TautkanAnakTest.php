<?php

use App\Enums\Hubungan;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use App\Notifications\AnakTertautNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    $this->wali = WaliMurid::factory()->create();
    $this->murid = Murid::factory()->create([
        'nama_panggilan' => 'Kirana',
        'tanggal_lahir' => '2021-03-09',
        'kode_tautan' => 'K7MZ4QXA',
        'kode_tautan_expired_at' => now()->addDays(14),
    ]);
});

it('menautkan anak dengan kode dan tanggal lahir yang cocok', function () {
    $kelas = Kelas::factory()->for(TahunAjaran::factory()->aktif())->create(['nama' => 'TK A2']);
    $this->murid->kelas()->attach($kelas);

    $this->actingAs($this->wali->user)->postJson('/api/v1/wali/tautkan-anak', [
        'kode' => 'K7MZ4QXA',
        'tanggal_lahir' => '2021-03-09',
        'hubungan' => 'ibu',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Kirana berhasil ditautkan ke akun Anda.')
        ->assertJsonPath('data.id', $this->murid->id)
        ->assertJsonPath('data.kelas', ['id' => $kelas->id, 'nama' => 'TK A2'])
        ->assertJsonPath('data.hubungan', 'ibu')
        ->assertJsonPath('data.is_kontak_utama', true);

    expect($this->wali->murid()->whereKey($this->murid->id)->exists())->toBeTrue();

    $log = Activity::query()->where('log_name', 'wali')->sole();
    expect($log->event)->toBe('tertaut')
        ->and($log->causer_id)->toBe($this->wali->user_id)
        ->and($log->subject_id)->toBe($this->murid->id);

    $this->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.wali_murid.anak.0.nama_panggilan', 'Kirana')
        ->assertJsonPath('data.wali_murid.anak.0.kelas', 'TK A2');
});

it('menerima kode yang diketik huruf kecil dengan spasi atau tanda hubung', function () {
    $this->actingAs($this->wali->user)->postJson('/api/v1/wali/tautkan-anak', [
        'kode' => ' k7mz-4qxa ',
        'tanggal_lahir' => '2021-03-09',
        'hubungan' => 'ayah',
    ])->assertOk();
});

it('menolak kode yang tidak ditemukan', function () {
    $this->actingAs($this->wali->user)->postJson('/api/v1/wali/tautkan-anak', [
        'kode' => 'ABCDEFGH',
        'tanggal_lahir' => '2021-03-09',
        'hubungan' => 'ibu',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.kode', ['Kode tautan tidak ditemukan. Periksa kembali kode yang diberikan sekolah.']);

    expect($this->wali->murid()->count())->toBe(0);
});

it('menolak kode yang sudah kedaluwarsa', function () {
    $this->murid->update(['kode_tautan_expired_at' => now()->subMinute()]);

    $this->actingAs($this->wali->user)->postJson('/api/v1/wali/tautkan-anak', [
        'kode' => 'K7MZ4QXA',
        'tanggal_lahir' => '2021-03-09',
        'hubungan' => 'ibu',
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.kode', ['Kode tautan sudah kedaluwarsa. Minta kode baru ke pihak sekolah.']);

    expect($this->wali->murid()->count())->toBe(0);
});

it('menolak tanggal lahir yang tidak cocok', function () {
    $this->actingAs($this->wali->user)->postJson('/api/v1/wali/tautkan-anak', [
        'kode' => 'K7MZ4QXA',
        'tanggal_lahir' => '2021-09-03',
        'hubungan' => 'ibu',
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.tanggal_lahir', ['Tanggal lahir tidak cocok dengan data anak untuk kode ini.']);

    expect($this->wali->murid()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('menolak anak yang sudah tertaut ke akun yang sama', function () {
    $this->wali->murid()->attach($this->murid, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    $this->actingAs($this->wali->user)->postJson('/api/v1/wali/tautkan-anak', [
        'kode' => 'K7MZ4QXA',
        'tanggal_lahir' => '2021-03-09',
        'hubungan' => 'ibu',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Kirana sudah tertaut ke akun Anda.');
});

it('membolehkan wali kedua memakai kode yang sama tanpa menjadi kontak utama', function () {
    $ibu = WaliMurid::factory()->create();
    $ibu->murid()->attach($this->murid, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $kepsek = buatKepalaSekolah();

    $this->actingAs($this->wali->user)->postJson('/api/v1/wali/tautkan-anak', [
        'kode' => 'K7MZ4QXA',
        'tanggal_lahir' => '2021-03-09',
        'hubungan' => 'ayah',
    ])
        ->assertOk()
        ->assertJsonPath('data.hubungan', 'ayah')
        ->assertJsonPath('data.is_kontak_utama', false);

    $namaAyah = $this->wali->user->name;
    Notification::assertSentTo($ibu->user, AnakTertautNotification::class, function (AnakTertautNotification $notifikasi) use ($ibu, $namaAyah): bool {
        return $notifikasi->toDatabase($ibu->user) === [
            'jenis' => 'anak_tertaut',
            'judul' => 'Wali murid baru tertaut',
            'pesan' => "{$namaAyah} (Ayah) menautkan akunnya ke Kirana.",
            'url' => '/dashboard/anak',
        ];
    });
    Notification::assertSentTo($kepsek, AnakTertautNotification::class, function (AnakTertautNotification $notifikasi) use ($kepsek): bool {
        return $notifikasi->toDatabase($kepsek)['url'] === "/dashboard/murid/{$this->murid->id}";
    });
    Notification::assertNotSentTo($this->wali->user, AnakTertautNotification::class);
});

it('memvalidasi isian tautkan anak', function (array $data, array $field) {
    $this->actingAs($this->wali->user)->postJson('/api/v1/wali/tautkan-anak', $data)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'kosong' => [[], ['kode', 'tanggal_lahir', 'hubungan']],
    'kode terlalu pendek' => [['kode' => 'K7MZ', 'tanggal_lahir' => '2021-03-09', 'hubungan' => 'ibu'], ['kode']],
    'format tanggal salah' => [['kode' => 'K7MZ4QXA', 'tanggal_lahir' => '09-03-2021', 'hubungan' => 'ibu'], ['tanggal_lahir']],
    'hubungan tidak dikenal' => [['kode' => 'K7MZ4QXA', 'tanggal_lahir' => '2021-03-09', 'hubungan' => 'kakek'], ['hubungan']],
]);

it('membatasi 5 percobaan tautkan anak per menit', function () {
    $data = ['kode' => 'ABCDEFGH', 'tanggal_lahir' => '2021-03-09', 'hubungan' => 'ibu'];

    foreach (range(1, 5) as $_) {
        $this->actingAs($this->wali->user)->postJson('/api/v1/wali/tautkan-anak', $data)->assertStatus(422);
    }

    $this->actingAs($this->wali->user)->postJson('/api/v1/wali/tautkan-anak', [...$data, 'kode' => 'K7MZ4QXA'])
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertHeader('Retry-After');

    expect($this->wali->murid()->count())->toBe(0);

    $this->travel(61)->seconds();
    $this->actingAs($this->wali->user)->postJson('/api/v1/wali/tautkan-anak', [...$data, 'kode' => 'K7MZ4QXA'])->assertOk();
});

it('menghitung batas percobaan per akun wali', function () {
    $data = ['kode' => 'ABCDEFGH', 'tanggal_lahir' => '2021-03-09', 'hubungan' => 'ibu'];

    foreach (range(1, 5) as $_) {
        $this->actingAs($this->wali->user)->postJson('/api/v1/wali/tautkan-anak', $data);
    }

    $this->actingAs(WaliMurid::factory()->create()->user)->postJson('/api/v1/wali/tautkan-anak', $data)->assertStatus(422);
});

it('hanya tersedia untuk wali murid', function () {
    $this->actingAs(buatGuru()->user)->postJson('/api/v1/wali/tautkan-anak', [
        'kode' => 'K7MZ4QXA',
        'tanggal_lahir' => '2021-03-09',
        'hubungan' => 'ibu',
    ])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
});
