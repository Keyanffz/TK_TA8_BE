<?php

use App\Enums\Hubungan;
use App\Enums\MetodeBayar;
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
use App\Notifications\PembayaranDiterimaNotification;
use App\Notifications\PembayaranDitolakNotification;
use App\Notifications\PembayaranMasukNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * Aisyah di TK A1 (wali kelas Bu Aini, tanpa izin keuangan), tertaut ke ayah dan ibunya. Bu Siti guru
 * berizin keuangan. Tagihan SPP Oktober Aisyah Rp 150.000 jatuh tempo 10 Oktober; hari ini 5 Oktober.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-05 19:30:00');
    Storage::fake('local');
    Notification::fake();

    $this->kepsek = buatKepalaSekolah();
    $tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->buAini = buatGuru();
    $this->buSiti = Guru::factory()->kelolaKeuangan()->create();
    $kelas = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id]);

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

function dataTransfer(array $timpa = []): array
{
    return [
        'bukti' => UploadedFile::fake()->image('transfer.jpg', 900, 1600),
        'tanggal_bayar' => '2026-10-05',
        'bank_pengirim' => 'BRI',
        'nama_pengirim' => 'Siti Maryam',
        ...$timpa,
    ];
}

function unggahBukti(object $test, WaliMurid $wali, Tagihan $tagihan): Pembayaran
{
    $id = $test->actingAs($wali->user)->post("/api/v1/tagihan/{$tagihan->id}/pembayaran", dataTransfer())->assertCreated()->json('data.id');

    return Pembayaran::query()->findOrFail($id);
}

it('menerima unggahan bukti transfer dari wali dan menunggu verifikasi', function () {
    $response = $this->actingAs($this->ibu->user)->post("/api/v1/tagihan/{$this->tagihan->id}/pembayaran", dataTransfer(['jumlah' => 1000]))
        ->assertCreated()
        ->assertJsonPath('message', 'Bukti transfer terkirim dan menunggu verifikasi petugas keuangan.')
        ->assertJsonPath('data.kode', 'PAY-20261005-00001')
        ->assertJsonPath('data.status', 'menunggu')
        ->assertJsonPath('data.metode', 'transfer')
        ->assertJsonPath('data.jumlah', 150000)
        ->assertJsonPath('data.dibayar_oleh.id', $this->ibu->user_id);

    $pembayaran = Pembayaran::query()->findOrFail($response->json('data.id'));
    Storage::disk('local')->assertExists((string) $pembayaran->bukti_path);
    expect($pembayaran->bukti_path)->toStartWith('bukti-bayar/')
        ->and($this->tagihan->fresh()?->status)->toBe(StatusTagihan::MenungguVerifikasi);
});

it('memberi tahu Kepala Sekolah dan guru berizin keuangan, bukan guru lain, saat bukti masuk', function () {
    unggahBukti($this, $this->ibu, $this->tagihan);

    Notification::assertSentTo([$this->kepsek, $this->buSiti->user], PembayaranMasukNotification::class, fn ($notifikasi, $channels, $penerima) => $notifikasi->toDatabase($penerima)['pesan'] === 'Bukti transfer Rp 150.000 untuk tagihan SPP Oktober 2026 (Aisyah Putri) menunggu verifikasi.');
    Notification::assertNotSentTo($this->buAini->user, PembayaranMasukNotification::class);
});

it('mengarahkan notifikasi pembayaran ke halaman detail tagihan di area petugas keuangan dan di area wali', function () {
    $pembayaran = unggahBukti($this, $this->ibu, $this->tagihan);
    $this->actingAs($this->kepsek)->postJson("/api/v1/pembayaran/{$pembayaran->id}/tolak", ['alasan' => 'Foto buram.'])->assertOk();

    Notification::assertSentTo($this->kepsek, PembayaranMasukNotification::class, fn ($notifikasi) => $notifikasi->toDatabase($this->kepsek)['url'] === "/mudarris/tagihan/{$this->tagihan->id}");
    Notification::assertSentTo($this->ibu->user, PembayaranDitolakNotification::class, fn ($notifikasi) => $notifikasi->toDatabase($this->ibu->user)['url'] === "/dashboard/tagihan/{$this->tagihan->id}");
});

it('menolak unggahan kedua selama masih ada bukti yang menunggu verifikasi', function () {
    unggahBukti($this, $this->ibu, $this->tagihan);

    $this->actingAs($this->ayah->user)->post("/api/v1/tagihan/{$this->tagihan->id}/pembayaran", dataTransfer())
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(Pembayaran::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('bukti-bayar'))->toHaveCount(1);
});

it('menjalankan alur lengkap: unggah, terima, tagihan lunas, wali diberi tahu, kwitansi tersedia', function () {
    $pembayaran = unggahBukti($this, $this->ibu, $this->tagihan);
    Carbon::setTestNow('2026-10-06 09:00:00');

    $this->actingAs($this->buSiti->user)->postJson("/api/v1/pembayaran/{$pembayaran->id}/terima")
        ->assertOk()
        ->assertJsonPath('data.status', 'diterima')
        ->assertJsonPath('data.diverifikasi_oleh.id', $this->buSiti->user_id);

    $tagihan = $this->tagihan->fresh();
    expect($tagihan?->status)->toBe(StatusTagihan::Lunas)
        ->and($tagihan?->lunas_at?->toDateTimeString())->toBe('2026-10-06 09:00:00');

    Notification::assertSentTo([$this->ibu->user, $this->ayah->user], PembayaranDiterimaNotification::class);
    $log = Activity::query()->where('log_name', 'pembayaran')->where('event', 'diterima')->sole();
    expect($log->causer_id)->toBe($this->buSiti->user_id);

    $kwitansi = $this->actingAs($this->ibu->user)->get("/api/v1/pembayaran/{$pembayaran->id}/kwitansi")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    expect($kwitansi->headers->get('content-disposition'))->toContain('kwitansi-PAY-20261005-00001.pdf')
        ->and(substr((string) $kwitansi->getContent(), 0, 4))->toBe('%PDF');
});

it('menolak bukti transfer, mengembalikan status tagihan, dan wali bisa mengunggah ulang', function () {
    $pembayaran = unggahBukti($this, $this->ibu, $this->tagihan);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pembayaran/{$pembayaran->id}/tolak", ['alasan' => 'Nominal di bukti transfer Rp 15.000, bukan Rp 150.000'])
        ->assertOk()
        ->assertJsonPath('data.status', 'ditolak')
        ->assertJsonPath('data.alasan_penolakan', 'Nominal di bukti transfer Rp 15.000, bukan Rp 150.000');

    expect($this->tagihan->fresh()?->status)->toBe(StatusTagihan::BelumBayar);
    Notification::assertSentTo($this->ibu->user, PembayaranDitolakNotification::class, fn ($notifikasi, $channels, $penerima) => $notifikasi->toDatabase($penerima)['pesan'] === 'Bukti transfer untuk tagihan SPP Oktober 2026 (Aisyah) ditolak: Nominal di bukti transfer Rp 15.000, bukan Rp 150.000. Silakan unggah ulang bukti yang benar.');
    expect(Activity::query()->where('event', 'ditolak')->sole()->properties['alasan'])->toBe('Nominal di bukti transfer Rp 15.000, bukan Rp 150.000');

    unggahBukti($this, $this->ayah, $this->tagihan);
    expect($this->tagihan->fresh()?->status)->toBe(StatusTagihan::MenungguVerifikasi);
});

it('mengembalikan tagihan ke terlambat kalau bukti ditolak setelah lewat jatuh tempo', function () {
    $pembayaran = unggahBukti($this, $this->ibu, $this->tagihan);
    Carbon::setTestNow('2026-10-12 08:00:00');

    $this->actingAs($this->kepsek)->postJson("/api/v1/pembayaran/{$pembayaran->id}/tolak", ['alasan' => 'Foto buram.'])->assertOk();

    expect($this->tagihan->fresh()?->status)->toBe(StatusTagihan::Terlambat);
});

it('menolak verifikasi ulang pembayaran yang sudah diterima atau ditolak', function (string $aksi) {
    $pembayaran = Pembayaran::factory()->for($this->tagihan)->create(['status' => StatusPembayaran::Ditolak]);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pembayaran/{$pembayaran->id}/{$aksi}", ['alasan' => 'Foto buram.'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
})->with(['terima', 'tolak']);

it('mencatat pembayaran tunai oleh petugas keuangan dan langsung melunasi tagihan', function () {
    $this->actingAs($this->buSiti->user)->postJson("/api/v1/tagihan/{$this->tagihan->id}/pembayaran", [
        'metode' => 'tunai',
        'tanggal_bayar' => '2026-10-05',
    ])
        ->assertCreated()
        ->assertJsonPath('data.metode', 'tunai')
        ->assertJsonPath('data.status', 'diterima')
        ->assertJsonPath('data.jumlah', 150000)
        ->assertJsonPath('data.bukti_url', null)
        ->assertJsonPath('data.dibayar_oleh', null)
        ->assertJsonPath('data.diverifikasi_oleh.id', $this->buSiti->user_id);

    expect($this->tagihan->fresh()?->status)->toBe(StatusTagihan::Lunas)
        ->and(Activity::query()->where('log_name', 'pembayaran')->where('event', 'tunai_dicatat')->count())->toBe(1);
    Notification::assertSentTo($this->ibu->user, PembayaranDiterimaNotification::class);
});

it('mencatat transfer oleh petugas keuangan tanpa bukti dan langsung melunasi tagihan', function () {
    $this->actingAs($this->kepsek)->postJson("/api/v1/tagihan/{$this->tagihan->id}/pembayaran", [
        'metode' => MetodeBayar::Transfer->value,
        'tanggal_bayar' => '2026-10-04',
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'Pembayaran transfer PAY-20261005-00001 tercatat dan tagihan lunas.')
        ->assertJsonPath('data.metode', 'transfer')
        ->assertJsonPath('data.status', 'diterima')
        ->assertJsonPath('data.bukti_url', null)
        ->assertJsonPath('data.bank_pengirim', null)
        ->assertJsonPath('data.diverifikasi_oleh.id', $this->kepsek->id);

    expect($this->tagihan->fresh()?->status)->toBe(StatusTagihan::Lunas)
        ->and(Activity::query()->where('log_name', 'pembayaran')->where('event', 'transfer_dicatat')->count())->toBe(1);
    Notification::assertSentTo([$this->ibu->user, $this->ayah->user], PembayaranDiterimaNotification::class);
    Notification::assertNotSentTo($this->buSiti->user, PembayaranMasukNotification::class);
});

it('menyimpan bukti transfer yang dilampirkan petugas keuangan', function () {
    $id = $this->actingAs($this->buSiti->user)->post("/api/v1/tagihan/{$this->tagihan->id}/pembayaran", dataTransfer(['metode' => 'transfer']))
        ->assertCreated()
        ->assertJsonPath('data.bank_pengirim', 'BRI')
        ->assertJsonPath('data.nama_pengirim', 'Siti Maryam')
        ->json('data.id');

    $pembayaran = Pembayaran::query()->findOrFail($id);
    Storage::disk('local')->assertExists((string) $pembayaran->bukti_path);
    expect($pembayaran->status)->toBe(StatusPembayaran::Diterima);

    $this->actingAs($this->ibu->user)->get("/api/v1/pembayaran/{$pembayaran->id}/bukti")->assertOk();
});

it('menolak bukti atau data pengirim pada pembayaran tunai dari petugas keuangan', function () {
    $this->actingAs($this->buSiti->user)->post("/api/v1/tagihan/{$this->tagihan->id}/pembayaran", dataTransfer(['metode' => 'tunai']), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('errors.bukti.0', 'Bukti hanya dilampirkan untuk pembayaran transfer.')
        ->assertJsonValidationErrors(['bank_pengirim', 'nama_pengirim']);

    expect(Storage::disk('local')->allFiles('bukti-bayar'))->toBe([]);
});

it('menghapus lagi bukti dari petugas keuangan kalau pencatatan ditolak', function () {
    $this->tagihan->update(['status' => StatusTagihan::Lunas]);

    $this->actingAs($this->buSiti->user)->post("/api/v1/tagihan/{$this->tagihan->id}/pembayaran", dataTransfer(['metode' => 'transfer']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(Storage::disk('local')->allFiles('bukti-bayar'))->toBe([]);
});

it('tetap mewajibkan bukti transfer dari wali walau mengirim metode tunai', function () {
    $this->actingAs($this->ibu->user)->postJson("/api/v1/tagihan/{$this->tagihan->id}/pembayaran", ['metode' => 'tunai', 'tanggal_bayar' => '2026-10-05'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bukti', 'bank_pengirim', 'nama_pengirim']);

    expect(Pembayaran::query()->count())->toBe(0);
});

it('menolak pembayaran untuk tagihan yang lunas atau dibatalkan', function (StatusTagihan $status) {
    $this->tagihan->update(['status' => $status]);

    $this->actingAs($this->ibu->user)->post("/api/v1/tagihan/{$this->tagihan->id}/pembayaran", dataTransfer())
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(Storage::disk('local')->allFiles('bukti-bayar'))->toBe([]);
})->with([StatusTagihan::Lunas, StatusTagihan::Dibatalkan]);

it('memvalidasi unggahan bukti transfer', function (array $data, string $field) {
    $this->actingAs($this->ibu->user)->post("/api/v1/tagihan/{$this->tagihan->id}/pembayaran", dataTransfer($data))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'tanpa bukti' => [['bukti' => null], 'bukti'],
    'bukti PDF' => [['bukti' => UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')], 'bukti'],
    'tanggal bayar di masa depan' => [['tanggal_bayar' => '2026-10-06'], 'tanggal_bayar'],
    'tanpa bank pengirim' => [['bank_pengirim' => ''], 'bank_pengirim'],
]);

it('membalas 404 saat wali membayar tagihan anak orang lain', function () {
    $this->actingAs(WaliMurid::factory()->create()->user)->post("/api/v1/tagihan/{$this->tagihan->id}/pembayaran", dataTransfer())
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('tidak mengizinkan guru tanpa izin keuangan membayar atau memverifikasi', function () {
    $pembayaran = Pembayaran::factory()->for($this->tagihan)->create();

    $this->actingAs($this->buAini->user)->postJson("/api/v1/tagihan/{$this->tagihan->id}/pembayaran", ['metode' => 'tunai', 'tanggal_bayar' => '2026-10-05'])
        ->assertForbidden();
    $this->actingAs($this->buAini->user)->postJson("/api/v1/pembayaran/{$pembayaran->id}/terima")
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
    $this->actingAs($this->buAini->user)->getJson('/api/v1/pembayaran')->assertForbidden();
    $this->actingAs($this->buAini->user)->getJson("/api/v1/pembayaran/{$pembayaran->id}")->assertForbidden();
});

it('memberi guru akses verifikasi setelah izin keuangannya diberikan, dan mencabutnya lagi', function () {
    $pembayaran = unggahBukti($this, $this->ibu, $this->tagihan);

    $this->actingAs($this->buAini->user)->postJson("/api/v1/pembayaran/{$pembayaran->id}/terima")->assertForbidden();

    $this->buAini->update(['bisa_kelola_keuangan' => true]);
    $this->actingAs($this->buAini->user->fresh())->getJson('/api/v1/pembayaran')->assertOk()->assertJsonPath('meta.total', 1);

    $this->buAini->update(['bisa_kelola_keuangan' => false]);
    $this->actingAs($this->buAini->user->fresh())->getJson('/api/v1/laporan/tunggakan')->assertForbidden();
});

it('menampilkan ke wali hanya pembayaran tagihan anaknya, dan ke petugas keuangan semuanya', function () {
    unggahBukti($this, $this->ibu, $this->tagihan);
    Pembayaran::factory()->for(Tagihan::factory())->create();

    $this->actingAs($this->ayah->user)->getJson('/api/v1/pembayaran')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.tagihan.kode', 'INV-202610-00001');
    $this->actingAs($this->buSiti->user)->getJson('/api/v1/pembayaran?filter[status]=menunggu')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('membalas 404 saat wali membuka pembayaran anak orang lain', function () {
    $milikLain = Pembayaran::factory()->for(Tagihan::factory())->create();

    $this->actingAs($this->ibu->user)->getJson("/api/v1/pembayaran/{$milikLain->id}")->assertNotFound();
    $this->actingAs($this->ibu->user)->get("/api/v1/pembayaran/{$milikLain->id}/bukti")->assertNotFound();
});

it('menyajikan file bukti transfer ke wali pemilik dan petugas keuangan', function () {
    $pembayaran = unggahBukti($this, $this->ibu, $this->tagihan);

    $this->actingAs($this->ayah->user)->get("/api/v1/pembayaran/{$pembayaran->id}/bukti")
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg');
    $this->actingAs($this->buSiti->user)->get("/api/v1/pembayaran/{$pembayaran->id}/bukti")->assertOk();
});

it('membalas 404 untuk bukti pembayaran tunai', function () {
    $tunai = Pembayaran::factory()->for($this->tagihan)->create(['metode' => MetodeBayar::Tunai, 'bukti_path' => null, 'status' => StatusPembayaran::Diterima]);

    $this->actingAs($this->kepsek)->get("/api/v1/pembayaran/{$tunai->id}/bukti")->assertNotFound();
});

it('menolak kwitansi untuk pembayaran yang belum diterima', function () {
    $pembayaran = unggahBukti($this, $this->ibu, $this->tagihan);

    $this->actingAs($this->ibu->user)->getJson("/api/v1/pembayaran/{$pembayaran->id}/kwitansi")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Kwitansi hanya tersedia untuk pembayaran yang sudah diterima.');
});
