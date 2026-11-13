<?php

use App\Enums\Hubungan;
use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use App\Enums\StatusPembayaran;
use App\Enums\StatusTagihan;
use App\Models\Guru;
use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaliMurid;
use App\Notifications\PembayaranDiterimaNotification;
use App\Services\KwitansiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

/**
 * Aisyah di TK A1: wali kelas Bu Aini, guru pendamping Bu Rina, keduanya tanpa izin keuangan. Bu Siti guru
 * berizin kelola keuangan, Bu Dewi wali kelas TK B1. Tagihan SPP Oktober Aisyah Rp 150.000 jatuh tempo
 * 10 Oktober; hari ini 5 Oktober.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-05 09:30:00');
    Storage::fake('local');
    Notification::fake();

    $this->kepsek = buatKepalaSekolah();
    $tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->buAini = buatGuru();
    $this->buRina = buatGuru();
    $this->buDewi = buatGuru();
    $this->buSiti = Guru::factory()->kelolaKeuangan()->create();
    $kelas = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id, 'guru_pendamping_id' => $this->buRina->id]);
    Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK B1', 'wali_kelas_id' => $this->buDewi->id]);

    $this->aisyah = Murid::factory()->create(['nama_lengkap' => 'Aisyah Putri', 'nama_panggilan' => 'Aisyah']);
    $kelas->murid()->attach($this->aisyah);
    $this->ibu = WaliMurid::factory()->create();
    $this->ayah = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($this->ibu, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $this->aisyah->waliMurid()->attach($this->ayah, ['hubungan' => Hubungan::Ayah, 'is_kontak_utama' => false]);

    $spp = JenisTagihan::factory()->for($tahunAjaran)->create(['nama' => 'SPP']);
    $this->tagihan = Tagihan::factory()->for($this->aisyah)->for($spp)->create([
        'kode' => 'INV-202610-00001', 'periode' => '2026-10-01', 'jatuh_tempo' => '2026-10-10', 'nominal' => 150000, 'total' => 150000,
    ]);
});

function catatTunai(object $test, User $petugas, Tagihan $tagihan, int $jumlah, string $tanggal = '2026-10-05'): TestResponse
{
    return $test->actingAs($petugas)->postJson("/api/v1/tagihan/{$tagihan->id}/pembayaran", ['jumlah' => $jumlah, 'tanggal_bayar' => $tanggal]);
}

it('mencatat cicilan tunai: tagihan tetap belum bayar dengan sisanya dan wali diberi tahu', function () {
    catatTunai($this, $this->buSiti->user, $this->tagihan, 100000)
        ->assertCreated()
        ->assertJsonPath('message', 'Pembayaran PAY-20261005-00001 tercatat. Sisa tagihan Rp 50.000.')
        ->assertJsonPath('data.kode', 'PAY-20261005-00001')
        ->assertJsonPath('data.metode', 'tunai')
        ->assertJsonPath('data.status', 'diterima')
        ->assertJsonPath('data.jumlah', 100000)
        ->assertJsonPath('data.dibayar_oleh', null)
        ->assertJsonPath('data.diverifikasi_oleh.id', $this->buSiti->user_id)
        ->assertJsonPath('data.tagihan.status', 'belum_bayar')
        ->assertJsonPath('data.tagihan.terbayar', 100000)
        ->assertJsonPath('data.tagihan.sisa', 50000);

    expect($this->tagihan->fresh()?->lunas_at)->toBeNull();
    Notification::assertSentTo([$this->ibu->user, $this->ayah->user], PembayaranDiterimaNotification::class, fn ($notifikasi, $channels, $penerima) => $notifikasi->toDatabase($penerima) === [
        'jenis' => 'pembayaran_diterima',
        'judul' => 'Pembayaran diterima',
        'pesan' => 'Pembayaran SPP Oktober 2026 untuk Aisyah sebesar Rp 100.000 sudah diterima. Sisa tagihan Rp 50.000. Kwitansi bisa diunduh dari halaman tagihan.',
        'url' => "/dashboard/tagihan/{$this->tagihan->id}",
    ]);
    $log = Activity::query()->where('log_name', 'pembayaran')->where('event', 'dicatat')->sole();
    expect($log->causer_id)->toBe($this->buSiti->user_id)
        ->and($log->properties['jumlah'])->toBe(100000)
        ->and($log->properties['sisa'])->toBe(50000);
});

it('melunasi tagihan saat cicilan terakhir menghabiskan sisa', function () {
    catatTunai($this, $this->kepsek, $this->tagihan, 100000)->assertCreated();
    Carbon::setTestNow('2026-10-08 10:00:00');

    catatTunai($this, $this->kepsek, $this->tagihan, 50000, '2026-10-08')
        ->assertCreated()
        ->assertJsonPath('message', 'Pembayaran PAY-20261008-00001 tercatat dan tagihan lunas.')
        ->assertJsonPath('data.tagihan.status', 'lunas')
        ->assertJsonPath('data.tagihan.sisa', 0);

    $tagihan = $this->tagihan->fresh();
    expect($tagihan?->status)->toBe(StatusTagihan::Lunas)
        ->and($tagihan?->lunas_at?->toDateTimeString())->toBe('2026-10-08 10:00:00')
        ->and(Pembayaran::query()->where('status', StatusPembayaran::Diterima)->sum('jumlah'))->toEqual(150000);
    Notification::assertSentTo($this->ibu->user, PembayaranDiterimaNotification::class, fn ($notifikasi, $channels, $penerima) => str_contains($notifikasi->toDatabase($penerima)['pesan'], 'sebesar Rp 50.000 sudah diterima. Tagihan sudah lunas.'));
});

it('menolak jumlah yang melebihi sisa tagihan', function () {
    catatTunai($this, $this->kepsek, $this->tagihan, 100000)->assertCreated();

    catatTunai($this, $this->kepsek, $this->tagihan, 50001)
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.jumlah.0', 'Jumlah melebihi sisa tagihan (Rp 50.000).');

    expect(Pembayaran::query()->count())->toBe(1);
});

it('memvalidasi isian pembayaran tunai', function (array $data, string $field) {
    $this->actingAs($this->kepsek)->postJson("/api/v1/tagihan/{$this->tagihan->id}/pembayaran", ['jumlah' => 50000, 'tanggal_bayar' => '2026-10-05', ...$data])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);

    expect(Pembayaran::query()->count())->toBe(0);
})->with([
    'jumlah nol' => [['jumlah' => 0], 'jumlah'],
    'jumlah pecahan' => [['jumlah' => 1500.5], 'jumlah'],
    'tanpa jumlah' => [['jumlah' => null], 'jumlah'],
    'tanggal bayar di masa depan' => [['tanggal_bayar' => '2026-10-06'], 'tanggal_bayar'],
    'tanpa tanggal bayar' => [['tanggal_bayar' => null], 'tanggal_bayar'],
]);

it('menolak pembayaran untuk tagihan yang lunas atau dibatalkan', function (StatusTagihan $status) {
    $this->tagihan->update(['status' => $status]);

    catatTunai($this, $this->kepsek, $this->tagihan, 50000)
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(Pembayaran::query()->count())->toBe(0);
})->with([StatusTagihan::Lunas, StatusTagihan::Dibatalkan]);

it('mengizinkan wali kelas mencatat pembayaran murid kelasnya dan mengunduh kwitansinya', function () {
    $id = catatTunai($this, $this->buAini->user, $this->tagihan, 75000)
        ->assertCreated()
        ->assertJsonPath('data.diverifikasi_oleh.id', $this->buAini->user_id)
        ->json('data.id');

    $this->actingAs($this->buAini->user)->getJson("/api/v1/pembayaran/{$id}")->assertOk()->assertJsonPath('data.jumlah', 75000);
    $this->actingAs($this->buAini->user)->get("/api/v1/pembayaran/{$id}/kwitansi")->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs($this->buAini->user)->getJson('/api/v1/pembayaran')->assertForbidden();
});

it('menolak guru pendamping dan wali kelas lain mencatat pembayaran', function () {
    catatTunai($this, $this->buRina->user, $this->tagihan, 50000)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    catatTunai($this, $this->buDewi->user, $this->tagihan, 50000)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');

    expect(Pembayaran::query()->count())->toBe(0);
});

it('tidak lagi mengizinkan wali kelas mencatat setelah murid keluar dari kelasnya', function () {
    $this->aisyah->kelasMurid()->update(['status' => 'keluar']);

    catatTunai($this, $this->buAini->user, $this->tagihan, 50000)->assertForbidden();
});

it('menolak wali murid mencatat pembayaran, membatalkan, dan tidak membuka endpoint verifikasi lama', function () {
    $pembayaran = Pembayaran::factory()->for($this->tagihan)->create();

    catatTunai($this, $this->ibu->user, $this->tagihan, 150000)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    $this->actingAs($this->ibu->user)->postJson("/api/v1/pembayaran/{$pembayaran->id}/batalkan", ['alasan' => 'Salah.'])->assertForbidden();
    $this->actingAs($this->kepsek)->postJson("/api/v1/pembayaran/{$pembayaran->id}/terima")->assertNotFound();
    $this->actingAs($this->kepsek)->postJson("/api/v1/pembayaran/{$pembayaran->id}/tolak", ['alasan' => 'Salah.'])->assertNotFound();
});

it('membatalkan pembayaran salah catat: tagihan lunas kembali belum bayar dan bisa dicatat ulang', function () {
    $id = catatTunai($this, $this->buAini->user, $this->tagihan, 150000)->assertCreated()->json('data.id');
    Carbon::setTestNow('2026-10-06 08:00:00');

    $this->actingAs($this->kepsek)->postJson("/api/v1/pembayaran/{$id}/batalkan", ['alasan' => 'Seharusnya Rp 100.000, salah ketik.'])
        ->assertOk()
        ->assertJsonPath('message', 'Pembayaran PAY-20261005-00001 dibatalkan.')
        ->assertJsonPath('data.status', 'dibatalkan')
        ->assertJsonPath('data.alasan_pembatalan', 'Seharusnya Rp 100.000, salah ketik.')
        ->assertJsonPath('data.dibatalkan_oleh.id', $this->kepsek->id)
        ->assertJsonPath('data.tagihan.status', 'belum_bayar')
        ->assertJsonPath('data.tagihan.sisa', 150000);

    $tagihan = $this->tagihan->fresh();
    $log = Activity::query()->where('log_name', 'pembayaran')->where('event', 'dibatalkan')->sole();
    expect($tagihan?->status)->toBe(StatusTagihan::BelumBayar)
        ->and($tagihan?->lunas_at)->toBeNull()
        ->and($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->properties['alasan'])->toBe('Seharusnya Rp 100.000, salah ketik.');

    catatTunai($this, $this->buAini->user, $this->tagihan, 100000, '2026-10-05')
        ->assertCreated()
        ->assertJsonPath('data.tagihan.sisa', 50000);
    $this->actingAs($this->kepsek)->getJson("/api/v1/tagihan/{$this->tagihan->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.pembayaran')
        ->assertJsonPath('data.terbayar', 100000);
});

it('mengembalikan tagihan ke terlambat kalau pembayaran dibatalkan setelah lewat jatuh tempo', function () {
    $id = catatTunai($this, $this->kepsek, $this->tagihan, 150000)->json('data.id');
    Carbon::setTestNow('2026-10-12 08:00:00');

    $this->actingAs($this->kepsek)->postJson("/api/v1/pembayaran/{$id}/batalkan", ['alasan' => 'Salah murid.'])->assertOk();

    expect($this->tagihan->fresh()?->status)->toBe(StatusTagihan::Terlambat);
});

it('membiarkan status tagihan yang belum lunas saat salah satu cicilannya dibatalkan', function () {
    $pertama = catatTunai($this, $this->kepsek, $this->tagihan, 50000)->json('data.id');
    catatTunai($this, $this->kepsek, $this->tagihan, 30000)->assertCreated();

    $this->actingAs($this->kepsek)->postJson("/api/v1/pembayaran/{$pertama}/batalkan", ['alasan' => 'Tercatat dua kali.'])
        ->assertOk()
        ->assertJsonPath('data.tagihan.terbayar', 30000)
        ->assertJsonPath('data.tagihan.sisa', 120000);
});

it('hanya mengizinkan Kepala Sekolah membatalkan pembayaran, dengan alasan', function () {
    $id = catatTunai($this, $this->buSiti->user, $this->tagihan, 50000)->json('data.id');

    $this->actingAs($this->buSiti->user)->postJson("/api/v1/pembayaran/{$id}/batalkan", ['alasan' => 'Salah ketik.'])->assertForbidden();
    $this->actingAs($this->buAini->user)->postJson("/api/v1/pembayaran/{$id}/batalkan", ['alasan' => 'Salah ketik.'])->assertForbidden();
    $this->actingAs($this->kepsek)->postJson("/api/v1/pembayaran/{$id}/batalkan", ['alasan' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['alasan']);

    expect(Pembayaran::query()->findOrFail($id)->status)->toBe(StatusPembayaran::Diterima);
});

it('menolak membatalkan pembayaran yang tidak berstatus diterima', function (StatusPembayaran $status) {
    $pembayaran = Pembayaran::factory()->for($this->tagihan)->create(['status' => $status]);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pembayaran/{$pembayaran->id}/batalkan", ['alasan' => 'Salah ketik.'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
})->with([StatusPembayaran::Dibatalkan, StatusPembayaran::Ditolak]);

it('membuat kwitansi tiap cicilan dengan yang sudah dibayar sebelumnya dan sisanya', function () {
    $pertama = catatTunai($this, $this->kepsek, $this->tagihan, 100000)->json('data.id');
    $kedua = catatTunai($this, $this->kepsek, $this->tagihan, 30000)->json('data.id');

    $kwitansi = $this->actingAs($this->ibu->user)->get("/api/v1/pembayaran/{$kedua}/kwitansi")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    expect($kwitansi->headers->get('content-disposition'))->toContain('kwitansi-PAY-20261005-00002.pdf')
        ->and(substr((string) $kwitansi->getContent(), 0, 4))->toBe('%PDF');

    $isi = fn (int $id): string => view('pdf.kwitansi', app(KwitansiService::class)->isi(Pembayaran::query()->findOrFail($id)))->render();
    expect($isi($pertama))->toContain('Rp 150.000')->toContain('Dibayar kali ini')->toContain('Rp 50.000')->not->toContain('Sudah dibayar sebelumnya')
        ->and($isi($kedua))->toContain('Sudah dibayar sebelumnya')->toContain('Rp 100.000')->toContain('Rp 30.000')->toContain('Rp 20.000');
});

it('menolak kwitansi untuk pembayaran yang dibatalkan', function () {
    $pembayaran = Pembayaran::factory()->for($this->tagihan)->create(['status' => StatusPembayaran::Dibatalkan]);

    $this->actingAs($this->ibu->user)->getJson("/api/v1/pembayaran/{$pembayaran->id}/kwitansi")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Kwitansi hanya tersedia untuk pembayaran yang sudah diterima.');
});

it('memberi guru akses mencatat setelah izin kelola keuangannya diberikan, dan mencabutnya lagi', function () {
    catatTunai($this, $this->buDewi->user, $this->tagihan, 50000)->assertNotFound();

    $izin = $this->buDewi->izin()->create(['modul' => ModulIzin::Keuangan, 'level' => LevelIzin::Kelola]);
    catatTunai($this, $this->buDewi->user->fresh(), $this->tagihan, 50000)->assertCreated();
    $this->actingAs($this->buDewi->user->fresh())->getJson('/api/v1/pembayaran')->assertOk()->assertJsonPath('meta.total', 1);

    $izin->delete();
    $this->actingAs($this->buDewi->user->fresh())->getJson('/api/v1/laporan/tunggakan')->assertForbidden();
});

it('menampilkan ke wali hanya pembayaran tagihan anaknya, dan ke petugas keuangan semuanya', function () {
    catatTunai($this, $this->kepsek, $this->tagihan, 50000)->assertCreated();
    Pembayaran::factory()->for(Tagihan::factory())->create(['status' => StatusPembayaran::Dibatalkan]);

    $this->actingAs($this->ayah->user)->getJson('/api/v1/pembayaran')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.tagihan.kode', 'INV-202610-00001')
        ->assertJsonPath('data.0.tagihan.sisa', 100000);
    $this->actingAs($this->buSiti->user)->getJson('/api/v1/pembayaran')->assertOk()->assertJsonPath('meta.total', 2);
    $this->actingAs($this->buSiti->user)->getJson('/api/v1/pembayaran?filter[status]=dibatalkan')->assertOk()->assertJsonPath('meta.total', 1);
});

it('membalas 404 saat wali membuka pembayaran anak orang lain', function () {
    $milikLain = Pembayaran::factory()->for(Tagihan::factory())->create();

    $this->actingAs($this->ibu->user)->getJson("/api/v1/pembayaran/{$milikLain->id}")->assertNotFound();
    $this->actingAs($this->ibu->user)->get("/api/v1/pembayaran/{$milikLain->id}/bukti")->assertNotFound();
    $this->actingAs($this->ibu->user)->get("/api/v1/pembayaran/{$milikLain->id}/kwitansi")->assertNotFound();
});

it('tetap menyajikan bukti transfer lama ke wali pemilik dan petugas keuangan, tidak ke wali kelas', function () {
    $lama = Pembayaran::factory()->for($this->tagihan)->transferLama()->create();
    Storage::disk('local')->put((string) $lama->bukti_path, 'isi-gambar');

    $this->actingAs($this->ayah->user)->get("/api/v1/pembayaran/{$lama->id}/bukti")->assertOk();
    $this->actingAs($this->buSiti->user)->get("/api/v1/pembayaran/{$lama->id}/bukti")->assertOk();
    $this->actingAs($this->buAini->user)->get("/api/v1/pembayaran/{$lama->id}/bukti")->assertForbidden();
});

it('membalas 404 untuk bukti pembayaran tunai', function () {
    $tunai = Pembayaran::factory()->for($this->tagihan)->create();

    $this->actingAs($this->kepsek)->get("/api/v1/pembayaran/{$tunai->id}/bukti")->assertNotFound();
});
