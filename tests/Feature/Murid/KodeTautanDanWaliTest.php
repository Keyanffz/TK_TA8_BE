<?php

use App\Enums\Hubungan;
use App\Enums\StatusMurid;
use App\Models\Murid;
use App\Models\WaliMurid;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
});

it('membuat kode tautan 8 karakter tanpa huruf atau angka yang mudah tertukar, berlaku 14 hari', function () {
    Carbon::setTestNow('2026-09-26 10:00:00');
    $murid = Murid::factory()->create(['nama_panggilan' => 'Aisyah']);

    $response = $this->actingAs($this->kepsek)->postJson("/api/v1/murid/{$murid->id}/kode-tautan")
        ->assertOk()
        ->assertJsonPath('data.expired_at', '2026-10-10T10:00:00+07:00')
        ->assertJsonPath('message', 'Kode tautan untuk Aisyah berlaku sampai 10 Oktober 2026.');

    expect($response->json('data.kode'))->toMatch('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/')
        ->and($murid->fresh()?->kode_tautan)->toBe($response->json('data.kode'));
});

it('mengganti kode lama sehingga kode lama tidak bisa dipakai lagi', function () {
    $murid = Murid::factory()->denganKodeTautan()->create(['tanggal_lahir' => '2021-05-02']);
    $kodeLama = $murid->kode_tautan;

    $this->actingAs($this->kepsek)->postJson("/api/v1/murid/{$murid->id}/kode-tautan")->assertOk();

    $this->actingAs(WaliMurid::factory()->create()->user)->postJson('/api/v1/wali/tautkan-anak', [
        'kode' => $kodeLama, 'tanggal_lahir' => '2021-05-02', 'hubungan' => 'ibu',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['kode']);
});

it('membuat kode yang bisa langsung dipakai wali untuk menautkan anak', function () {
    $murid = Murid::factory()->create(['tanggal_lahir' => '2021-05-02']);
    $kode = $this->actingAs($this->kepsek)->postJson("/api/v1/murid/{$murid->id}/kode-tautan")->json('data.kode');

    $this->actingAs(WaliMurid::factory()->create()->user)->postJson('/api/v1/wali/tautkan-anak', [
        'kode' => $kode, 'tanggal_lahir' => '2021-05-02', 'hubungan' => 'ayah',
    ])->assertOk();
});

it('menolak membuat kode tautan untuk murid yang tidak aktif', function () {
    $murid = Murid::factory()->create(['status' => StatusMurid::Lulus, 'tanggal_keluar' => '2026-06-20']);

    $this->actingAs($this->kepsek)->postJson("/api/v1/murid/{$murid->id}/kode-tautan")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
});

it('melepas tautan wali dan memindahkan kontak utama ke wali lain', function () {
    $murid = Murid::factory()->create(['nama_lengkap' => 'Bima Saputra']);
    $ayah = WaliMurid::factory()->create();
    $ibu = WaliMurid::factory()->create();
    $murid->waliMurid()->attach($ayah, ['hubungan' => Hubungan::Ayah, 'is_kontak_utama' => true]);
    $murid->waliMurid()->attach($ibu, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => false]);

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/murid/{$murid->id}/wali/{$ayah->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Tautan wali dilepas dari Bima Saputra.');

    $wali = $murid->waliMurid()->get();
    expect($wali->modelKeys())->toBe([$ibu->id])
        ->and($wali->first()?->pivot->is_kontak_utama)->toBeTrue();

    $log = Activity::query()->where('log_name', 'wali')->where('event', 'dilepas')->sole();
    expect($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->subject_id)->toBe($murid->id)
        ->and($log->properties['wali_murid_id'])->toBe($ayah->id);
});

it('mengubah hubungan wali dan memindahkan kontak utama sehingga tetap satu per murid', function () {
    $murid = Murid::factory()->create(['nama_lengkap' => 'Bima Saputra', 'nama_panggilan' => 'Bima']);
    $ayah = WaliMurid::factory()->create();
    $ibu = WaliMurid::factory()->create();
    $murid->waliMurid()->attach($ayah, ['hubungan' => Hubungan::Ayah, 'is_kontak_utama' => true]);
    $murid->waliMurid()->attach($ibu, ['hubungan' => Hubungan::Wali, 'is_kontak_utama' => false]);

    $wali = $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$murid->id}/wali/{$ibu->id}", ['hubungan' => 'ibu', 'is_kontak_utama' => true])
        ->assertOk()
        ->assertJsonPath('message', 'Data wali Bima tersimpan.')
        ->json('data.wali');

    expect(collect($wali)->keyBy('id')->map(fn (array $satu) => [$satu['hubungan'], $satu['is_kontak_utama']])->all())
        ->toBe([$ayah->id => ['ayah', false], $ibu->id => ['ibu', true]]);
    $log = Activity::query()->where('log_name', 'wali')->where('event', 'diubah')->sole();
    expect($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->properties->all())->toBe(['wali_murid_id' => $ibu->id, 'hubungan' => 'ibu', 'is_kontak_utama' => true]);
});

it('hanya mengubah field yang dikirim pada tautan wali', function () {
    $murid = Murid::factory()->create();
    $ayah = WaliMurid::factory()->create();
    $murid->waliMurid()->attach($ayah, ['hubungan' => Hubungan::Wali, 'is_kontak_utama' => true]);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$murid->id}/wali/{$ayah->id}", ['hubungan' => 'ayah'])
        ->assertOk()
        ->assertJsonPath('data.wali.0.hubungan', 'ayah')
        ->assertJsonPath('data.wali.0.is_kontak_utama', true);
});

it('menolak melepas status kontak utama tanpa memilih wali lain', function () {
    $murid = Murid::factory()->create(['nama_panggilan' => 'Bima']);
    $ayah = WaliMurid::factory()->create();
    $ibu = WaliMurid::factory()->create();
    $murid->waliMurid()->attach($ayah, ['hubungan' => Hubungan::Ayah, 'is_kontak_utama' => true]);
    $murid->waliMurid()->attach($ibu, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => false]);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$murid->id}/wali/{$ayah->id}", ['is_kontak_utama' => false])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', "{$ayah->user->name} adalah kontak utama Bima. Jadikan wali lain sebagai kontak utama terlebih dahulu.");
    $this->patchJson("/api/v1/murid/{$murid->id}/wali/{$ibu->id}", ['is_kontak_utama' => false])->assertOk();

    expect($murid->waliMurid()->wherePivot('is_kontak_utama', true)->pluck('wali_murid.id')->all())->toBe([$ayah->id]);
});

it('memvalidasi perubahan tautan wali dan membalas 404 untuk wali yang tidak tertaut', function () {
    $murid = Murid::factory()->create();
    $wali = WaliMurid::factory()->create();
    $murid->waliMurid()->attach($wali, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$murid->id}/wali/{$wali->id}", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['hubungan', 'is_kontak_utama']);
    $this->patchJson("/api/v1/murid/{$murid->id}/wali/{$wali->id}", ['hubungan' => 'kakek'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('hubungan');
    $this->patchJson('/api/v1/murid/'.$murid->id.'/wali/'.WaliMurid::factory()->create()->id, ['hubungan' => 'ayah'])
        ->assertNotFound();
    $this->actingAs($wali->user)->patchJson("/api/v1/murid/{$murid->id}/wali/{$wali->id}", ['hubungan' => 'ayah'])
        ->assertForbidden();
});

it('menutup akses wali yang tautannya dilepas', function () {
    $murid = Murid::factory()->create();
    $wali = WaliMurid::factory()->create();
    $murid->waliMurid()->attach($wali, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/murid/{$murid->id}/wali/{$wali->id}")->assertOk();

    $this->actingAs($wali->user)->getJson("/api/v1/murid/{$murid->id}")->assertNotFound();
});

it('membalas 404 saat melepas wali yang tidak tertaut ke murid itu', function () {
    $murid = Murid::factory()->create();

    $this->actingAs($this->kepsek)->deleteJson('/api/v1/murid/'.$murid->id.'/wali/'.WaliMurid::factory()->create()->id)
        ->assertNotFound();
});

it('hanya Kepala Sekolah yang bisa membuat kode tautan dan melepas wali', function () {
    $murid = Murid::factory()->create();
    $wali = WaliMurid::factory()->create();
    $murid->waliMurid()->attach($wali, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    $this->actingAs($wali->user)->postJson("/api/v1/murid/{$murid->id}/kode-tautan")->assertForbidden();
    $this->actingAs($wali->user)->deleteJson("/api/v1/murid/{$murid->id}/wali/{$wali->id}")->assertForbidden();
});

it('mengosongkan kode tautan yang kedaluwarsa lewat command', function () {
    $kedaluwarsa = Murid::factory()->create(['kode_tautan' => 'ABCD2345', 'kode_tautan_expired_at' => now()->subDay()]);
    $masihBerlaku = Murid::factory()->denganKodeTautan()->create();

    $this->artisan('kode-tautan:bersihkan', ['--dry-run' => true])
        ->expectsOutputToContain('1 kode tautan kedaluwarsa akan dikosongkan')
        ->assertSuccessful();
    expect($kedaluwarsa->fresh()?->kode_tautan)->toBe('ABCD2345');

    $this->artisan('kode-tautan:bersihkan')->assertSuccessful();

    expect($kedaluwarsa->fresh()?->kode_tautan)->toBeNull()
        ->and($kedaluwarsa->fresh()?->kode_tautan_expired_at)->toBeNull()
        ->and($masihBerlaku->fresh()?->kode_tautan)->not->toBeNull();
});

it('menjadwalkan pembersihan kode tautan setiap hari', function () {
    $jadwal = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'kode-tautan:bersihkan'));

    expect($jadwal)->not->toBeNull()
        ->and($jadwal->expression)->toBe('0 1 * * *');
});
