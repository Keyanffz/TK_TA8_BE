<?php

use App\Enums\Hubungan;
use App\Enums\PeriodeTagihan;
use App\Enums\StatusAkun;
use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Enums\StatusTagihan;
use App\Enums\TipeKeringanan;
use App\Models\Guru;
use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\Keringanan;
use App\Models\Murid;
use App\Models\Pengaturan;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaliMurid;
use App\Notifications\TagihanBaruNotification;
use App\Notifications\TagihanTertundaNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * Tahun ajaran aktif 2026/2027 (13 Juli 2026 – 25 Juni 2027), SPP Rp 150.000, jatuh tempo tanggal 10.
 * Aisyah di TK A1 (tertaut ke ibunya), Bima di TK B1.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-01 00:10:00');
    Notification::fake();

    $this->kepsek = buatKepalaSekolah();
    $this->tahunAjaran = TahunAjaran::factory()->aktif()->create([
        'nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-25',
    ]);
    Pengaturan::query()->create(['kunci' => 'keuangan.tanggal_jatuh_tempo', 'nilai' => 10, 'grup' => 'keuangan']);
    $this->spp = JenisTagihan::factory()->for($this->tahunAjaran)->create(['nama' => 'SPP', 'nominal' => 150000]);

    $this->kelasA1 = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK A1', 'tingkat' => 'A']);
    $this->kelasB1 = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK B1', 'tingkat' => 'B']);
    $this->aisyah = Murid::factory()->create(['nama_panggilan' => 'Aisyah']);
    $this->bima = Murid::factory()->create(['nama_panggilan' => 'Bima']);
    $this->kelasA1->murid()->attach($this->aisyah);
    $this->kelasB1->murid()->attach($this->bima);

    $this->ibuAisyah = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($this->ibuAisyah, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
});

function tagihanSpp(Murid $murid): ?Tagihan
{
    return Tagihan::query()->where('murid_id', $murid->id)->whereDate('periode', '2026-10-01')->first();
}

it('membuat tagihan bulanan untuk semua murid aktif yang punya kelas di tahun ajaran aktif', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])
        ->assertOk()
        ->assertJsonPath('data', ['dibuat' => 2, 'dilewati' => 0])
        ->assertJsonPath('message', 'Tagihan Oktober 2026: 2 dibuat, 0 sudah ada.');

    $tagihan = tagihanSpp($this->aisyah);
    expect($tagihan?->kode)->toBe('INV-202610-00001')
        ->and($tagihan?->jatuh_tempo->toDateString())->toBe('2026-10-10')
        ->and($tagihan?->nominal)->toBe(150000)
        ->and($tagihan?->potongan)->toBe(0)
        ->and($tagihan?->total)->toBe(150000)
        ->and($tagihan?->status)->toBe(StatusTagihan::BelumBayar)
        ->and($tagihan?->tahun_ajaran_id)->toBe($this->tahunAjaran->id)
        ->and($tagihan?->dibuat_oleh)->toBe($this->kepsek->id)
        ->and(tagihanSpp($this->bima)?->kode)->toBe('INV-202610-00002');
});

it('tidak membuat tagihan ganda saat generate diulang untuk periode yang sama', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])->assertOk();

    $this->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])
        ->assertOk()
        ->assertJsonPath('data', ['dibuat' => 0, 'dilewati' => 2]);

    expect(Tagihan::query()->count())->toBe(2);
});

it('hanya membuat tagihan untuk murid yang belum punya, saat murid baru masuk di tengah bulan', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])->assertOk();
    $citra = Murid::factory()->create();
    $this->kelasA1->murid()->attach($citra);

    $this->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])
        ->assertJsonPath('data', ['dibuat' => 1, 'dilewati' => 2]);

    expect(tagihanSpp($citra)?->kode)->toBe('INV-202610-00003');
});

it('menerapkan keringanan: persen dibulatkan ke bawah, nominal tidak melebihi tagihan, total nol langsung lunas', function () {
    $this->spp->update(['nominal' => 155555]);
    $citra = Murid::factory()->create();
    $this->kelasA1->murid()->attach($citra);
    Keringanan::factory()->for($this->aisyah)->for($this->spp)->create(['tipe' => TipeKeringanan::Persen, 'nilai' => 33]);
    Keringanan::factory()->for($this->bima)->for($this->spp)->create(['tipe' => TipeKeringanan::Nominal, 'nilai' => 50000]);
    Keringanan::factory()->for($citra)->for($this->spp)->create(['tipe' => TipeKeringanan::Nominal, 'nilai' => 200000]);

    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])->assertOk();

    expect(tagihanSpp($this->aisyah)?->only(['nominal', 'potongan', 'total']))->toBe(['nominal' => 155555, 'potongan' => 51333, 'total' => 104222])
        ->and(tagihanSpp($this->bima)?->only(['potongan', 'total']))->toBe(['potongan' => 50000, 'total' => 105555])
        ->and(tagihanSpp($citra)?->only(['potongan', 'total']))->toBe(['potongan' => 155555, 'total' => 0])
        ->and(tagihanSpp($citra)?->status)->toBe(StatusTagihan::Lunas)
        ->and(tagihanSpp($citra)?->lunas_at)->not->toBeNull();
});

it('memakai keringanan yang masa berlakunya menyentuh bulan periode', function () {
    Keringanan::factory()->for($this->aisyah)->for($this->spp)->create(['nilai' => 50, 'berlaku_mulai' => '2026-07-01', 'berlaku_sampai' => '2026-09-30']);
    Keringanan::factory()->for($this->bima)->for($this->spp)->create(['nilai' => 50, 'berlaku_mulai' => '2026-10-15']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])->assertOk();

    expect(tagihanSpp($this->aisyah)?->potongan)->toBe(0)
        ->and(tagihanSpp($this->bima)?->potongan)->toBe(75000);
});

it('memilih jenis tagihan sesuai tingkat kelas murid', function () {
    $sppB = JenisTagihan::factory()->for($this->tahunAjaran)->create(['nama' => 'Infaq Kelompok B', 'nominal' => 20000, 'tingkat' => 'B']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])
        ->assertJsonPath('data', ['dibuat' => 3, 'dilewati' => 0]);

    expect(Tagihan::query()->where('jenis_tagihan_id', $sppB->id)->pluck('murid_id')->all())->toBe([$this->bima->id]);
});

it('melewati murid tidak aktif atau tanpa kelas, serta jenis tagihan nonaktif atau sekali bayar', function () {
    $pindah = Murid::factory()->create(['status' => StatusMurid::Pindah, 'tanggal_keluar' => '2026-09-15']);
    $this->kelasA1->murid()->attach($pindah, ['status' => StatusKelasMurid::Keluar]);
    Murid::factory()->create();
    Kelas::factory()->for(TahunAjaran::factory()->create(['nama' => '2025/2026']))->create()->murid()->attach(Murid::factory()->create());
    JenisTagihan::factory()->for($this->tahunAjaran)->create(['nama' => 'Ekstrakurikuler', 'is_aktif' => false]);
    JenisTagihan::factory()->for($this->tahunAjaran)->sekali()->create();

    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])
        ->assertJsonPath('data', ['dibuat' => 2, 'dilewati' => 0]);

    expect(Tagihan::query()->pluck('murid_id')->sort()->values()->all())->toBe([$this->aisyah->id, $this->bima->id]);
});

it('menolak periode di luar tahun ajaran aktif', function (string $periode) {
    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => $periode])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(Tagihan::query()->count())->toBe(0);
})->with(['sebelum tahun ajaran mulai' => ['2026-06'], 'setelah tahun ajaran selesai' => ['2027-07']]);

it('menerima bulan pertama dan terakhir tahun ajaran walau tahun ajaran tidak mulai tanggal 1', function (string $periode) {
    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => $periode])
        ->assertOk()
        ->assertJsonPath('data.dibuat', 2);
})->with(['Juli 2026' => ['2026-07'], 'Juni 2027' => ['2027-06']]);

it('melanjutkan nomor kode tagihan yang sudah dipakai di bulan yang sama', function () {
    Tagihan::factory()->for(Murid::factory())->for(JenisTagihan::factory()->for($this->tahunAjaran)->sekali())->create([
        'kode' => 'INV-202610-00007', 'periode' => null,
    ]);

    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])->assertOk();

    expect(tagihanSpp($this->aisyah)?->kode)->toBe('INV-202610-00008');
});

it('memberi tahu wali tentang tagihan baru dengan pesan yang spesifik', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])->assertOk();

    Notification::assertSentTo($this->ibuAisyah->user, TagihanBaruNotification::class, function (TagihanBaruNotification $notifikasi, array $channels, object $penerima): bool {
        $isi = $notifikasi->toDatabase($penerima);

        return $isi['jenis'] === 'tagihan_baru'
            && $isi['pesan'] === 'Tagihan SPP Oktober 2026 untuk Aisyah sebesar Rp 150.000 jatuh tempo 10 Oktober.'
            && $isi['url'] === '/dashboard/tagihan/'.tagihanSpp($this->aisyah)?->id;
    });
});

it('tidak memberi tahu wali untuk tagihan yang langsung lunas karena keringanan penuh', function () {
    Keringanan::factory()->for($this->aisyah)->for($this->spp)->create(['nilai' => 100]);

    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])->assertOk();

    Notification::assertNotSentTo($this->ibuAisyah->user, TagihanBaruNotification::class);
});

it('mencatat generate tagihan di log aktivitas', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])->assertOk();

    $log = Activity::query()->where('log_name', 'tagihan')->where('event', 'generate')->sole();
    expect($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->properties->all())->toBe(['periode' => '2026-10', 'dibuat' => 2, 'dilewati' => 0]);
});

it('hanya Kepala Sekolah yang bisa generate tagihan manual', function () {
    $this->actingAs(Guru::factory()->kelolaKeuangan()->create()->user)
        ->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])
        ->assertForbidden();
});

it('menjalankan generate lewat command dengan periode bawaan bulan ini dan tanpa pelaku', function () {
    $this->artisan('tagihan:generate')
        ->expectsOutputToContain('Tagihan Oktober 2026: 2 dibuat, 0 sudah ada.')
        ->assertSuccessful();

    expect(tagihanSpp($this->aisyah)?->dibuat_oleh)->toBeNull()
        ->and(Activity::query()->where('event', 'generate')->sole()->causer_id)->toBeNull();
});

it('hanya menghitung saat command dijalankan dengan --dry-run', function () {
    $this->artisan('tagihan:generate', ['--periode' => '2026-11', '--dry-run' => true])
        ->expectsOutputToContain('[dry run] Tagihan November 2026: 2 akan dibuat')
        ->assertSuccessful();

    expect(Tagihan::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('menolak format periode yang salah dan periode di luar tahun ajaran di command', function () {
    $this->artisan('tagihan:generate', ['--periode' => '10-2026'])->assertExitCode(2);
    $this->artisan('tagihan:generate', ['--periode' => '2027-08'])->assertFailed();
});

it('memberi tahu Kepala Sekolah saat generate terjadwal melewati bulan di luar tahun ajaran aktif', function () {
    Carbon::setTestNow('2027-07-01 00:10:00');
    $kepsekNonaktif = User::factory()->superAdmin()->status(StatusAkun::Nonaktif)->create();

    $this->artisan('tagihan:generate')->assertFailed();

    expect(Tagihan::query()->count())->toBe(0);
    Notification::assertSentTo($this->kepsek, TagihanTertundaNotification::class, function ($notifikasi) {
        $isi = $notifikasi->toDatabase($this->kepsek);

        return $isi['jenis'] === 'tagihan_tertunda'
            && $isi['judul'] === 'Tagihan Juli 2027 belum dibuat'
            && $isi['pesan'] === 'Tagihan bulanan Juli 2027 belum dibuat karena bulan itu di luar tahun ajaran aktif 2026/2027. Aktifkan tahun ajaran yang sesuai, lalu buat tagihannya lewat generate tagihan manual.'
            && $isi['url'] === '/dashboard/tahun-ajaran';
    });
    Notification::assertNotSentTo($kepsekNonaktif, TagihanTertundaNotification::class);
});

it('memberi tahu Kepala Sekolah saat generate terjadwal berjalan tanpa tahun ajaran aktif', function () {
    $this->tahunAjaran->update(['is_aktif' => false]);

    $this->artisan('tagihan:generate')->assertFailed();

    Notification::assertSentTo($this->kepsek, TagihanTertundaNotification::class, fn ($notifikasi) => str_contains($notifikasi->toDatabase($this->kepsek)['pesan'], 'karena belum ada tahun ajaran aktif'));
});

it('tidak mengirim pemberitahuan tagihan tertunda untuk --periode, --dry-run, atau generate manual', function () {
    Carbon::setTestNow('2027-07-01 00:10:00');

    $this->artisan('tagihan:generate', ['--periode' => '2027-08'])->assertFailed();
    $this->artisan('tagihan:generate', ['--dry-run' => true])->assertFailed();
    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => '2027-07'])->assertStatus(422);

    Notification::assertNothingSent();
});

it('menjadwalkan command keuangan sesuai B6.2', function () {
    $jadwal = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn ($event) => [trim((string) preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command)) => $event->expression]);

    expect($jadwal->get('tagihan:generate'))->toBe('10 0 1 * *')
        ->and($jadwal->get('tagihan:tandai-terlambat'))->toBe('30 0 * * *')
        ->and($jadwal->get('tagihan:pengingat'))->toBe('0 7 * * *');
});

it('tidak mengubah periode tagihan bulanan yang sudah ada saat nominal jenis tagihan diubah', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/tagihan/generate', ['periode' => '2026-10'])->assertOk();

    $this->putJson("/api/v1/jenis-tagihan/{$this->spp->id}", [
        'tahun_ajaran_id' => $this->tahunAjaran->id,
        'nama' => 'SPP',
        'nominal' => 175000,
        'periode' => PeriodeTagihan::Bulanan->value,
    ])->assertOk();

    expect(tagihanSpp($this->aisyah)?->total)->toBe(150000);
});
