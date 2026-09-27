<?php

use App\Enums\Hubungan;
use App\Models\Murid;
use App\Models\WaliMurid;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
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

it('hanya Kepala Sekolah yang bisa melepas wali', function () {
    $murid = Murid::factory()->create();
    $wali = WaliMurid::factory()->create();
    $murid->waliMurid()->attach($wali, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    $this->actingAs($wali->user)->deleteJson("/api/v1/murid/{$murid->id}/wali/{$wali->id}")->assertForbidden();
});
