<?php

use App\Enums\Hubungan;
use App\Models\Murid;
use App\Models\OrangTua;
use App\Models\WaliMurid;
use Spatie\Activitylog\Models\Activity;

/**
 * Ibu Rina wali murid dari Galih. Data Galih seperti data lama: formulir baru terisi alamat dan nama ayah saja.
 */
beforeEach(function () {
    $this->ibuRina = WaliMurid::factory()->create();
    $this->galih = Murid::factory()->create(['nik' => null, 'kecamatan' => 'Tembalang']);
    $this->galih->waliMurid()->attach($this->ibuRina, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    OrangTua::query()->create(['murid_id' => $this->galih->id, 'peran' => Hubungan::Ayah, 'nama' => 'Yusuf Maulana']);
});

function isiDataAnak(array $timpa = []): array
{
    return [
        'nik' => '3374011205220003',
        'desa_kelurahan' => 'Sendangmulyo',
        'kabupaten_kota' => 'Kota Semarang',
        'provinsi' => 'Jawa Tengah',
        'kewarganegaraan' => 'wni',
        'status_yatim_piatu' => 'tidak',
        'kontak_utama' => 'ibu',
        'ayah' => [
            'status' => 'kandung', 'tempat_lahir' => 'Semarang', 'tanggal_lahir' => '1988-04-10', 'agama' => 'Islam',
            'kewarganegaraan' => 'wni', 'pendidikan_terakhir' => 'd4_s1', 'pekerjaan' => 'pns_tni_polri', 'alamat_sama_dengan_anak' => true,
        ],
        'ibu' => [
            'status' => 'kandung', 'nama' => 'Rina Kartika', 'tempat_lahir' => 'Demak', 'tanggal_lahir' => '1991-09-01', 'agama' => 'Islam',
            'kewarganegaraan' => 'wni', 'pendidikan_terakhir' => 'slta', 'pekerjaan' => 'mengurus_rumah_tangga', 'alamat_sama_dengan_anak' => true,
            'no_hp' => '085640112233',
        ],
        ...$timpa,
    ];
}

it('mengisi data anak yang masih kosong dan menandainya lengkap', function () {
    $this->actingAs($this->ibuRina->user)->patchJson("/api/v1/wali/anak/{$this->galih->id}", isiDataAnak())
        ->assertOk()
        ->assertJsonPath('data.nik', '3374011205220003')
        ->assertJsonPath('data.orang_tua.ayah.nama', 'Yusuf Maulana')
        ->assertJsonPath('data.orang_tua.ayah.pekerjaan', 'pns_tni_polri')
        ->assertJsonPath('data.data_kurang', [])
        ->assertJsonPath('data.data_lengkap', true);

    $this->actingAs($this->ibuRina->user)->getJson('/api/v1/wali/anak')->assertOk()->assertJsonPath('data.0.data_lengkap', true);

    $log = Activity::query()->where('event', 'dilengkapi_wali')->sole();
    expect($log->properties->get('field'))->toContain('nik', 'ayah.pekerjaan', 'ibu.nama')
        ->and(json_encode($log->properties))->not->toContain('3374011205220003');
});

it('menolak perubahan isian yang sudah terisi tetapi menerima nilai yang sama', function () {
    $this->actingAs($this->ibuRina->user)->patchJson("/api/v1/wali/anak/{$this->galih->id}", [
        'kecamatan' => 'Banyumanik',
        'ayah' => ['nama' => 'Yusuf M.'],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'kecamatan' => 'Kecamatan sudah terisi. Perubahan data yang sudah ada dilakukan lewat sekolah.',
            'ayah.nama' => 'Nama ayah sudah terisi. Perubahan data yang sudah ada dilakukan lewat sekolah.',
        ]);

    $this->actingAs($this->ibuRina->user)->patchJson("/api/v1/wali/anak/{$this->galih->id}", ['kecamatan' => 'Tembalang', 'asal_sekolah' => 'KB Tunas Harapan'])
        ->assertOk()
        ->assertJsonPath('data.asal_sekolah', 'KB Tunas Harapan');
    expect($this->galih->fresh()?->kecamatan)->toBe('Tembalang');
});

it('menganggap alamat rumah terisi kalau sudah dicentang sama dengan alamat anak', function () {
    OrangTua::query()->where('murid_id', $this->galih->id)->update(['alamat_sama_dengan_anak' => true]);

    $this->actingAs($this->ibuRina->user)->patchJson("/api/v1/wali/anak/{$this->galih->id}", ['ayah' => ['alamat_rumah' => 'Jl. Kaligawe No. 3']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ayah.alamat_rumah']);
});

it('menolak kontak utama yang belum punya nomor HP dan NIK yang sudah dipakai murid lain', function () {
    Murid::factory()->create(['nik' => '3374011205220003']);

    $this->actingAs($this->ibuRina->user)->patchJson("/api/v1/wali/anak/{$this->galih->id}", ['kontak_utama' => 'ayah'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ayah.no_hp']);
    $this->actingAs($this->ibuRina->user)->patchJson("/api/v1/wali/anak/{$this->galih->id}", ['nik' => '3374011205220003'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nik']);
});

it('membalas 404 untuk anak yang tidak tertaut ke akun wali itu', function () {
    $lain = Murid::factory()->create();

    $this->actingAs($this->ibuRina->user)->patchJson("/api/v1/wali/anak/{$lain->id}", ['asal_sekolah' => 'TK Melati'])->assertNotFound();
    $this->actingAs(buatKepalaSekolah())->patchJson("/api/v1/wali/anak/{$this->galih->id}", ['asal_sekolah' => 'TK Melati'])->assertForbidden();
});
