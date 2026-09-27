<?php

use App\Enums\Hubungan;
use App\Enums\StatusPembayaran;
use App\Enums\StatusTagihan;
use App\Models\Guru;
use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * SPP Oktober 2026 untuk Aisyah (TK A1): Rp 150.000, jatuh tempo 10 Oktober, hari ini 5 Oktober.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-05 10:00:00');

    $this->kepsek = buatKepalaSekolah();
    $this->bendahara = Guru::factory()->kelolaKeuangan()->create()->user;
    $tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $spp = JenisTagihan::factory()->for($tahunAjaran)->create(['nama' => 'SPP', 'nominal' => 150000]);
    $this->aisyah = Murid::factory()->create(['nama_panggilan' => 'Aisyah']);
    Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK A1'])->murid()->attach($this->aisyah);

    $this->tagihan = Tagihan::factory()->for($this->aisyah)->for($spp)->create([
        'kode' => 'INV-202610-00001', 'periode' => '2026-10-01', 'jatuh_tempo' => '2026-10-10',
    ]);
});

it('mengubah jatuh tempo, potongan, dan catatan lalu menghitung ulang total', function () {
    $this->actingAs($this->bendahara)->putJson("/api/v1/tagihan/{$this->tagihan->id}", [
        'jatuh_tempo' => '2026-10-20',
        'potongan' => 50000,
        'catatan' => 'Potongan untuk anak kedua.',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Tagihan INV-202610-00001 tersimpan.')
        ->assertJsonPath('data.jatuh_tempo', '2026-10-20')
        ->assertJsonPath('data.nominal', 150000)
        ->assertJsonPath('data.potongan', 50000)
        ->assertJsonPath('data.total', 100000)
        ->assertJsonPath('data.status', 'belum_bayar')
        ->assertJsonPath('data.catatan', 'Potongan untuk anak kedua.');

    $log = Activity::query()->where('log_name', 'tagihan')->where('event', 'diubah')->sole();
    expect($log->causer_id)->toBe($this->bendahara->id)
        ->and($log->properties['sebelum'])->toBe(['jatuh_tempo' => '2026-10-10', 'potongan' => 0, 'total' => 150000, 'status' => 'belum_bayar'])
        ->and($log->properties['sesudah'])->toBe(['jatuh_tempo' => '2026-10-20', 'potongan' => 50000, 'total' => 100000, 'status' => 'belum_bayar']);
});

it('hanya mengubah field yang dikirim', function () {
    $this->tagihan->update(['catatan' => 'Catatan lama.']);

    $this->actingAs($this->kepsek)->putJson("/api/v1/tagihan/{$this->tagihan->id}", ['potongan' => 25000])
        ->assertOk()
        ->assertJsonPath('data.jatuh_tempo', '2026-10-10')
        ->assertJsonPath('data.total', 125000)
        ->assertJsonPath('data.catatan', 'Catatan lama.');
});

it('mengembalikan tagihan terlambat ke belum bayar kalau jatuh temponya dimundurkan', function () {
    $this->tagihan->update(['jatuh_tempo' => '2026-10-01', 'status' => StatusTagihan::Terlambat]);

    $this->actingAs($this->kepsek)->putJson("/api/v1/tagihan/{$this->tagihan->id}", ['potongan' => 10000])
        ->assertJsonPath('data.status', 'terlambat');

    $this->putJson("/api/v1/tagihan/{$this->tagihan->id}", ['jatuh_tempo' => '2026-10-15'])
        ->assertOk()
        ->assertJsonPath('data.status', 'belum_bayar');
});

it('melunasi tagihan yang potongannya sebesar nominal', function () {
    $this->actingAs($this->kepsek)->putJson("/api/v1/tagihan/{$this->tagihan->id}", ['potongan' => 150000])
        ->assertOk()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('data.status', 'lunas');

    expect($this->tagihan->fresh()?->lunas_at?->toDateTimeString())->toBe('2026-10-05 10:00:00');
});

it('memvalidasi potongan dan jatuh tempo', function (array $data, string $field) {
    $this->actingAs($this->kepsek)->putJson("/api/v1/tagihan/{$this->tagihan->id}", $data)
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors($field);

    expect($this->tagihan->fresh()?->total)->toBe(150000);
})->with([
    'potongan melebihi nominal' => [['potongan' => 150001], 'potongan'],
    'potongan negatif' => [['potongan' => -1], 'potongan'],
    'jatuh tempo baru sudah lewat' => [['jatuh_tempo' => '2026-10-04'], 'jatuh_tempo'],
    'format jatuh tempo' => [['jatuh_tempo' => '10-10-2026'], 'jatuh_tempo'],
]);

it('menerima jatuh tempo lama yang sudah lewat kalau tidak diubah', function () {
    $this->tagihan->update(['jatuh_tempo' => '2026-10-01', 'status' => StatusTagihan::Terlambat]);

    $this->actingAs($this->kepsek)->putJson("/api/v1/tagihan/{$this->tagihan->id}", ['jatuh_tempo' => '2026-10-01', 'potongan' => 20000])
        ->assertOk()
        ->assertJsonPath('data.total', 130000);
});

it('menolak mengubah tagihan yang lunas, dibatalkan, atau punya bukti menunggu verifikasi', function (Closure $siapkan) {
    $siapkan($this->tagihan);

    $this->actingAs($this->kepsek)->putJson("/api/v1/tagihan/{$this->tagihan->id}", ['potongan' => 10000])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect($this->tagihan->fresh()?->potongan)->toBe(0);
})->with([
    'lunas' => [fn (Tagihan $tagihan) => $tagihan->update(['status' => StatusTagihan::Lunas])],
    'dibatalkan' => [fn (Tagihan $tagihan) => $tagihan->update(['status' => StatusTagihan::Dibatalkan])],
    'bukti menunggu verifikasi' => [function (Tagihan $tagihan) {
        Pembayaran::factory()->for($tagihan)->create(['status' => StatusPembayaran::Menunggu]);
        $tagihan->update(['status' => StatusTagihan::MenungguVerifikasi]);
    }],
]);

it('menutup perubahan tagihan untuk guru tanpa izin keuangan dan wali murid', function () {
    $wali = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($wali, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    $this->actingAs(buatGuru()->user)->putJson("/api/v1/tagihan/{$this->tagihan->id}", ['potongan' => 10000])->assertForbidden();
    $this->actingAs($wali->user)->putJson("/api/v1/tagihan/{$this->tagihan->id}", ['potongan' => 10000])->assertForbidden();
});
