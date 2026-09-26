<?php

use App\Enums\Hubungan;
use App\Enums\StatusKelasMurid;
use App\Enums\StatusPembayaran;
use App\Enums\StatusTagihan;
use App\Models\Guru;
use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\Keringanan;
use App\Models\Murid;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use App\Notifications\TagihanBaruNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * Seragam Rp 350.000 (sekali bayar). TK A1 berisi Aisyah dan Bima (aktif) serta Citra (sudah keluar).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    Notification::fake();

    $this->kepsek = buatKepalaSekolah();
    $this->bendahara = Guru::factory()->kelolaKeuangan()->create()->user;
    $tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->seragam = JenisTagihan::factory()->for($tahunAjaran)->sekali()->create(['nama' => 'Seragam', 'nominal' => 350000]);
    $this->spp = JenisTagihan::factory()->for($tahunAjaran)->create(['nama' => 'SPP']);

    $this->kelasA1 = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK A1', 'tingkat' => 'A']);
    $this->aisyah = Murid::factory()->create(['nama_panggilan' => 'Aisyah']);
    $this->bima = Murid::factory()->create();
    $this->kelasA1->murid()->attach([$this->aisyah->id, $this->bima->id]);
    $this->kelasA1->murid()->attach(Murid::factory()->create(), ['status' => StatusKelasMurid::Keluar]);

    $this->ibuAisyah = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($this->ibuAisyah, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
});

it('membuat tagihan sekali bayar untuk semua murid aktif satu kelas', function () {
    $this->actingAs($this->bendahara)->postJson('/api/v1/tagihan', [
        'jenis_tagihan_id' => $this->seragam->id,
        'kelas_id' => $this->kelasA1->id,
        'jatuh_tempo' => '2026-10-31',
    ])
        ->assertCreated()
        ->assertJsonPath('data', ['dibuat' => 2, 'dilewati' => 0]);

    $tagihan = Tagihan::query()->where('murid_id', $this->aisyah->id)->sole();
    expect($tagihan->kode)->toBe('INV-202610-00001')
        ->and($tagihan->periode)->toBeNull()
        ->and($tagihan->total)->toBe(350000)
        ->and($tagihan->jatuh_tempo->toDateString())->toBe('2026-10-31')
        ->and($tagihan->dibuat_oleh)->toBe($this->bendahara->id);
    Notification::assertSentTo($this->ibuAisyah->user, TagihanBaruNotification::class, fn ($notifikasi, $channels, $penerima) => $notifikasi->toDatabase($penerima)['pesan'] === 'Tagihan Seragam untuk Aisyah sebesar Rp 350.000 jatuh tempo 31 Oktober.');
});

it('melewati murid yang sudah punya tagihan jenis itu, kecuali yang dibatalkan', function () {
    Tagihan::factory()->for($this->aisyah)->for($this->seragam)->create(['periode' => null]);
    Tagihan::factory()->for($this->bima)->for($this->seragam)->create(['periode' => null, 'status' => StatusTagihan::Dibatalkan]);

    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan', [
        'jenis_tagihan_id' => $this->seragam->id,
        'murid_ids' => [$this->aisyah->id, $this->bima->id],
        'jatuh_tempo' => '2026-10-31',
    ])->assertJsonPath('data', ['dibuat' => 1, 'dilewati' => 1]);

    expect(Tagihan::query()->where('murid_id', $this->bima->id)->where('status', StatusTagihan::BelumBayar)->count())->toBe(1);
});

it('melewati murid yang tingkat kelasnya tidak sesuai jenis tagihan', function () {
    $this->seragam->update(['tingkat' => 'B']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan', [
        'jenis_tagihan_id' => $this->seragam->id,
        'murid_ids' => [$this->aisyah->id],
        'jatuh_tempo' => '2026-10-31',
    ])->assertJsonPath('data', ['dibuat' => 0, 'dilewati' => 1]);
});

it('menerapkan keringanan yang berlaku pada tanggal pembuatan', function () {
    Keringanan::factory()->for($this->aisyah)->for($this->seragam)->create(['nilai' => 50, 'berlaku_mulai' => '2026-10-01']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan', [
        'jenis_tagihan_id' => $this->seragam->id,
        'murid_ids' => [$this->aisyah->id],
        'jatuh_tempo' => '2026-10-31',
    ])->assertCreated();

    expect(Tagihan::query()->where('murid_id', $this->aisyah->id)->sole()->only(['potongan', 'total']))
        ->toBe(['potongan' => 175000, 'total' => 175000]);
});

it('menolak tagihan sekali untuk jenis tagihan bulanan', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan', [
        'jenis_tagihan_id' => $this->spp->id,
        'murid_ids' => [$this->aisyah->id],
        'jatuh_tempo' => '2026-10-31',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
});

it('memvalidasi sasaran dan jatuh tempo tagihan sekali', function (array $data, string $field) {
    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan', [
        'jenis_tagihan_id' => $this->seragam->id,
        'jatuh_tempo' => '2026-10-31',
        ...$data,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'tanpa murid maupun kelas' => [[], 'murid_ids'],
    'murid dan kelas sekaligus' => [['murid_ids' => [1], 'kelas_id' => 1], 'murid_ids'],
    'jatuh tempo sudah lewat' => [['kelas_id' => 1, 'jatuh_tempo' => '2026-10-01'], 'jatuh_tempo'],
]);

it('tidak mengizinkan guru tanpa izin keuangan membuat tagihan', function () {
    $this->actingAs(buatGuru()->user)->postJson('/api/v1/tagihan', [
        'jenis_tagihan_id' => $this->seragam->id,
        'kelas_id' => $this->kelasA1->id,
        'jatuh_tempo' => '2026-10-31',
    ])->assertForbidden();
});

it('membatalkan tagihan beserta alasan dan mencatatnya di log aktivitas', function () {
    $tagihan = Tagihan::factory()->for($this->aisyah)->for($this->spp)->create();

    $this->actingAs($this->kepsek)->patchJson("/api/v1/tagihan/{$tagihan->id}/batalkan", ['alasan' => 'Murid pindah sebelum bulan berjalan.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'dibatalkan')
        ->assertJsonPath('data.catatan', 'Murid pindah sebelum bulan berjalan.');

    $log = Activity::query()->where('log_name', 'tagihan')->where('event', 'dibatalkan')->sole();
    expect($log->subject_id)->toBe($tagihan->id)
        ->and($log->properties['alasan'])->toBe('Murid pindah sebelum bulan berjalan.');
});

it('menolak membatalkan tagihan yang lunas, sudah dibatalkan, atau punya bukti menunggu verifikasi', function (Closure $siapkan) {
    $tagihan = Tagihan::factory()->for($this->aisyah)->for($this->spp)->create();
    $siapkan($tagihan);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/tagihan/{$tagihan->id}/batalkan", ['alasan' => 'Salah input.'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
})->with([
    'lunas' => [fn (Tagihan $tagihan) => $tagihan->update(['status' => StatusTagihan::Lunas])],
    'sudah dibatalkan' => [fn (Tagihan $tagihan) => $tagihan->update(['status' => StatusTagihan::Dibatalkan])],
    'bukti menunggu verifikasi' => [function (Tagihan $tagihan) {
        Pembayaran::factory()->for($tagihan)->create(['status' => StatusPembayaran::Menunggu]);
        $tagihan->update(['status' => StatusTagihan::MenungguVerifikasi]);
    }],
]);

it('hanya Kepala Sekolah yang bisa membatalkan tagihan', function () {
    $tagihan = Tagihan::factory()->for($this->aisyah)->for($this->spp)->create();

    $this->actingAs($this->bendahara)->patchJson("/api/v1/tagihan/{$tagihan->id}/batalkan", ['alasan' => 'Salah input.'])
        ->assertForbidden();
});
