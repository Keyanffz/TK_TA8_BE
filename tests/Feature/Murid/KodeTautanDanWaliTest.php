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
