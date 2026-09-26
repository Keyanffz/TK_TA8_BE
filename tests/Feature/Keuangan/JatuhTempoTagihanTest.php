<?php

use App\Enums\Hubungan;
use App\Enums\StatusTagihan;
use App\Models\JenisTagihan;
use App\Models\Murid;
use App\Models\Pengaturan;
use App\Models\Tagihan;
use App\Models\WaliMurid;
use App\Notifications\PengingatTagihanNotification;
use App\Notifications\TagihanTerlambatNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Hari ini 7 Oktober 2026, pengingat H-3. Tagihan SPP Aisyah jatuh tempo 10 Oktober (diingatkan hari ini),
 * tagihan Agustus jatuh tempo 10 Agustus (lewat), dan satu tagihan lewat jatuh tempo yang buktinya
 * sedang diverifikasi.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-07 07:00:00');
    Notification::fake();
    Pengaturan::query()->create(['kunci' => 'keuangan.hari_pengingat', 'nilai' => 3, 'grup' => 'keuangan']);

    $this->aisyah = Murid::factory()->create(['nama_panggilan' => 'Aisyah']);
    $this->ibu = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($this->ibu, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $spp = JenisTagihan::factory()->create(['nama' => 'SPP']);

    $this->oktober = Tagihan::factory()->for($this->aisyah)->for($spp)->create(['periode' => '2026-10-01', 'jatuh_tempo' => '2026-10-10']);
    $this->agustus = Tagihan::factory()->for($this->aisyah)->for($spp)->create(['periode' => '2026-08-01', 'jatuh_tempo' => '2026-08-10']);
    $this->diverifikasi = Tagihan::factory()->for($this->aisyah)->for($spp)->create([
        'periode' => '2026-09-01', 'jatuh_tempo' => '2026-09-10', 'status' => StatusTagihan::MenungguVerifikasi,
    ]);
});

it('menandai tagihan belum bayar yang lewat jatuh tempo sebagai terlambat dan memberi tahu wali', function () {
    $this->artisan('tagihan:tandai-terlambat')
        ->expectsOutputToContain('1 tagihan ditandai terlambat.')
        ->assertSuccessful();

    expect($this->agustus->fresh()?->status)->toBe(StatusTagihan::Terlambat)
        ->and($this->oktober->fresh()?->status)->toBe(StatusTagihan::BelumBayar)
        ->and($this->diverifikasi->fresh()?->status)->toBe(StatusTagihan::MenungguVerifikasi);
    Notification::assertSentTo($this->ibu->user, TagihanTerlambatNotification::class, fn ($notifikasi, $channels, $penerima) => $notifikasi->toDatabase($penerima)['pesan'] === 'Tagihan SPP Agustus 2026 untuk Aisyah sebesar Rp 150.000 sudah lewat jatuh tempo 10 Agustus. Mohon segera dibayar.');
    Notification::assertSentTimes(TagihanTerlambatNotification::class, 1);
});

it('tidak menandai tagihan pada hari jatuh temponya', function () {
    Carbon::setTestNow('2026-10-10 00:30:00');

    $this->artisan('tagihan:tandai-terlambat')->assertSuccessful();

    expect($this->oktober->fresh()?->status)->toBe(StatusTagihan::BelumBayar);
});

it('mengirim pengingat H-3 sebelum jatuh tempo', function () {
    $this->artisan('tagihan:pengingat')
        ->expectsOutputToContain('Pengingat dikirim untuk 1 tagihan.')
        ->assertSuccessful();

    Notification::assertSentTo($this->ibu->user, PengingatTagihanNotification::class, function ($notifikasi, $channels, $penerima) {
        $isi = $notifikasi->toDatabase($penerima);

        return $isi['jenis'] === 'pengingat_tagihan'
            && $isi['pesan'] === 'Tagihan SPP Oktober 2026 untuk Aisyah sebesar Rp 150.000 jatuh tempo 3 hari lagi (10 Oktober).'
            && $isi['url'] === "/dashboard/tagihan/{$this->oktober->id}";
    });
});

it('tidak mengubah apa pun saat command dijalankan dengan --dry-run', function () {
    $this->artisan('tagihan:tandai-terlambat', ['--dry-run' => true])
        ->expectsOutputToContain('[dry run] 1 tagihan akan ditandai terlambat.')
        ->assertSuccessful();
    $this->artisan('tagihan:pengingat', ['--dry-run' => true])
        ->expectsOutputToContain('[dry run] 1 tagihan akan diingatkan.')
        ->assertSuccessful();

    expect($this->agustus->fresh()?->status)->toBe(StatusTagihan::BelumBayar);
    Notification::assertNothingSent();
});
