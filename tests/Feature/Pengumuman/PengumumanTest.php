<?php

use App\Enums\Hubungan;
use App\Enums\StatusAkun;
use App\Enums\TargetPengumuman;
use App\Jobs\KirimNotifikasiPengumuman;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pengumuman;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaliMurid;
use App\Notifications\PengumumanBaruNotification;
use App\Services\PengumumanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * TK A1 diampu Bu Aini, TK A2 diampu Bu Dwi. Aisyah (TK A1) tertaut ke ibunya, Bima (TK A2) ke ayahnya.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    Notification::fake();

    $this->kepsek = buatKepalaSekolah();
    $tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->buAini = buatGuru();
    $this->buDwi = buatGuru();
    $this->kelasA1 = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id]);
    $this->kelasA2 = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK A2', 'wali_kelas_id' => $this->buDwi->id]);

    $this->aisyah = Murid::factory()->create(['nama_lengkap' => 'Aisyah Putri']);
    $this->bima = Murid::factory()->create(['nama_lengkap' => 'Bima Saputra']);
    $this->kelasA1->murid()->attach($this->aisyah);
    $this->kelasA2->murid()->attach($this->bima);
    $this->ibuAisyah = WaliMurid::factory()->create();
    $this->ayahBima = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($this->ibuAisyah, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $this->bima->waliMurid()->attach($this->ayahBima, ['hubungan' => Hubungan::Ayah, 'is_kontak_utama' => true]);
});

function pengumumanUntuk(User $penulis, TargetPengumuman $target, array $atribut = []): Pengumuman
{
    return Pengumuman::factory()->create(['penulis_id' => $penulis->id, 'target' => $target, ...$atribut]);
}

it('menyusun feed pengumuman sesuai role dan target', function () {
    $semua = pengumumanUntuk($this->kepsek, TargetPengumuman::Semua);
    $untukGuru = pengumumanUntuk($this->kepsek, TargetPengumuman::Guru);
    $untukWali = pengumumanUntuk($this->kepsek, TargetPengumuman::WaliMurid);
    $kelasA1 = pengumumanUntuk($this->buAini->user, TargetPengumuman::Kelas);
    $kelasA1->kelas()->attach($this->kelasA1);
    $muridBima = pengumumanUntuk($this->kepsek, TargetPengumuman::Murid);
    $muridBima->murid()->attach($this->bima);
    $draftDwi = pengumumanUntuk($this->buDwi->user, TargetPengumuman::Kelas, ['published_at' => null]);
    $draftDwi->kelas()->attach($this->kelasA2);

    $feed = fn (User $user) => collect($this->actingAs($user)->getJson('/api/v1/pengumuman')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    $ids = fn (Pengumuman ...$daftar) => collect($daftar)->pluck('id')->sort()->values()->all();

    expect($feed($this->kepsek))->toBe($ids($semua, $untukGuru, $untukWali, $kelasA1, $muridBima, $draftDwi))
        ->and($feed($this->buAini->user))->toBe($ids($semua, $untukGuru, $kelasA1))
        ->and($feed($this->buDwi->user))->toBe($ids($semua, $untukGuru, $muridBima, $draftDwi))
        ->and($feed($this->ibuAisyah->user))->toBe($ids($semua, $untukWali, $kelasA1))
        ->and($feed($this->ayahBima->user))->toBe($ids($semua, $untukWali, $muridBima));

    $this->actingAs($this->ibuAisyah->user)->getJson("/api/v1/pengumuman/{$muridBima->id}")->assertNotFound();
    $this->actingAs($this->buAini->user)->getJson("/api/v1/pengumuman/{$draftDwi->id}")->assertNotFound();
});

it('mengurutkan pengumuman yang disematkan lebih dulu, lalu terbaru', function () {
    $lama = pengumumanUntuk($this->kepsek, TargetPengumuman::Semua, ['published_at' => '2026-09-01 08:00:00']);
    $baru = pengumumanUntuk($this->kepsek, TargetPengumuman::Semua, ['published_at' => '2026-10-01 08:00:00']);
    $disematkan = pengumumanUntuk($this->kepsek, TargetPengumuman::Semua, ['published_at' => '2026-08-01 08:00:00', 'is_pinned' => true]);

    $this->actingAs($this->ibuAisyah->user)->getJson('/api/v1/pengumuman')
        ->assertJsonPath('data.*.id', [$disematkan->id, $baru->id, $lama->id]);
});

it('tidak menampilkan daftar murid sasaran ke wali murid', function () {
    $pengumuman = pengumumanUntuk($this->kepsek, TargetPengumuman::Murid);
    $pengumuman->murid()->attach([$this->aisyah->id, $this->bima->id]);

    $this->actingAs($this->ibuAisyah->user)->getJson("/api/v1/pengumuman/{$pengumuman->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.murid')
        ->assertJsonMissingPath('data.kelas');

    $this->actingAs($this->kepsek)->getJson("/api/v1/pengumuman/{$pengumuman->id}")
        ->assertJsonCount(2, 'data.murid');
});

it('menerbitkan pengumuman untuk kelas yang diampu guru dan memberi tahu wali serta guru kelas itu', function () {
    $response = $this->actingAs($this->buAini->user)->postJson('/api/v1/pengumuman', [
        'judul' => 'Membawa botol plastik bekas',
        'isi' => '<p>Mohon anak membawa <strong>satu botol</strong> plastik bekas.</p><script>alert(1)</script>',
        'target' => 'kelas',
        'kelas_ids' => [$this->kelasA1->id],
        'publish' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'Pengumuman diterbitkan.')
        ->assertJsonPath('data.slug', 'membawa-botol-plastik-bekas')
        ->assertJsonPath('data.isi', '<p>Mohon anak membawa <strong>satu botol</strong> plastik bekas.</p>')
        ->assertJsonPath('data.kelas', [['id' => $this->kelasA1->id, 'nama' => 'TK A1']])
        ->assertJsonPath('data.published_at', '2026-10-05T10:00:00+07:00');

    $url = '/dashboard/pengumuman/'.$response->json('data.id');
    Notification::assertSentTo($this->ibuAisyah->user, PengumumanBaruNotification::class, fn ($notifikasi) => $notifikasi->toDatabase($this->ibuAisyah->user)
        === ['jenis' => 'pengumuman_baru', 'judul' => 'Membawa botol plastik bekas', 'pesan' => 'Mohon anak membawa satu botol plastik bekas.', 'url' => $url]);
    Notification::assertNotSentTo([$this->buAini->user, $this->ayahBima->user, $this->buDwi->user, $this->kepsek], PengumumanBaruNotification::class);
});

it('menolak guru menyasar kelas atau murid di luar kelasnya, dan target selain kelas atau murid', function () {
    $this->actingAs($this->buAini->user)->postJson('/api/v1/pengumuman', [
        'judul' => 'Kunjungan perpustakaan', 'isi' => '<p>Isi</p>', 'target' => 'kelas', 'kelas_ids' => [$this->kelasA2->id], 'publish' => true,
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors', ['kelas_ids.0' => ['Anda hanya bisa memilih kelas yang Anda ampu.']]);

    $this->actingAs($this->buAini->user)->postJson('/api/v1/pengumuman', [
        'judul' => 'Pengingat', 'isi' => '<p>Isi</p>', 'target' => 'murid', 'murid_ids' => [$this->bima->id], 'publish' => true,
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors', ['murid_ids.0' => ['Anda hanya bisa memilih murid di kelas yang Anda ampu.']]);

    $this->actingAs($this->buAini->user)->postJson('/api/v1/pengumuman', [
        'judul' => 'Libur', 'isi' => '<p>Isi</p>', 'target' => 'semua', 'publish' => true,
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.target.0', 'Guru hanya bisa membuat pengumuman untuk kelas yang diampu atau murid di kelasnya.');

    expect(Pengumuman::query()->count())->toBe(0);
});

it('memberi tahu wali murid yang disasar dan guru kelasnya untuk target murid', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/pengumuman', [
        'judul' => 'Pengingat pembayaran SPP September', 'isi' => '<p>SPP September sudah lewat jatuh tempo.</p>',
        'target' => 'murid', 'murid_ids' => [$this->bima->id], 'publish' => true,
    ])->assertCreated();

    Notification::assertSentTo([$this->ayahBima->user, $this->buDwi->user], PengumumanBaruNotification::class);
    Notification::assertNotSentTo([$this->ibuAisyah->user, $this->buAini->user], PengumumanBaruNotification::class);
});

it('memberi tahu semua guru dan wali aktif untuk target semua, kecuali penulis dan akun nonaktif', function () {
    $waliNonaktif = User::factory()->waliMurid()->status(StatusAkun::Nonaktif)->create();

    $this->actingAs($this->kepsek)->postJson('/api/v1/pengumuman', [
        'judul' => 'Libur Maulid Nabi', 'isi' => '<p>Sekolah libur.</p>', 'target' => 'semua', 'is_publik' => true, 'publish' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('data.is_publik', true);

    Notification::assertSentTo([$this->buAini->user, $this->buDwi->user, $this->ibuAisyah->user, $this->ayahBima->user], PengumumanBaruNotification::class);
    Notification::assertNotSentTo([$this->kepsek, $waliNonaktif], PengumumanBaruNotification::class);
});

it('menolak is_publik untuk target selain semua', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/pengumuman', [
        'judul' => 'Rapat guru', 'isi' => '<p>Rapat.</p>', 'target' => 'guru', 'is_publik' => true, 'publish' => false,
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.is_publik.0', 'Hanya pengumuman untuk semua yang bisa ditampilkan di website.');
});

it('menyimpan draft tanpa notifikasi, lalu memberi tahu sekali saat diterbitkan', function () {
    $data = ['judul' => 'Rapat guru', 'isi' => '<p>Rapat di ruang guru.</p>', 'target' => 'guru', 'publish' => false];

    $id = $this->actingAs($this->kepsek)->postJson('/api/v1/pengumuman', $data)
        ->assertCreated()
        ->assertJsonPath('message', 'Pengumuman disimpan sebagai draft.')
        ->assertJsonPath('data.published_at', null)
        ->json('data.id');
    Notification::assertNothingSent();

    $this->actingAs($this->kepsek)->putJson("/api/v1/pengumuman/{$id}", [...$data, 'publish' => true])->assertOk();
    Carbon::setTestNow('2026-10-06 10:00:00');
    $this->actingAs($this->kepsek)->putJson("/api/v1/pengumuman/{$id}", [...$data, 'judul' => 'Rapat guru (ruang berubah)', 'publish' => true])
        ->assertOk()
        ->assertJsonPath('data.published_at', '2026-10-05T10:00:00+07:00')
        ->assertJsonPath('data.slug', 'rapat-guru');

    Notification::assertSentToTimes($this->buAini->user, PengumumanBaruNotification::class, 1);
});

it('membuat slug unik untuk judul yang sama', function () {
    $data = ['judul' => 'Libur Maulid Nabi', 'isi' => '<p>Libur.</p>', 'target' => 'semua', 'publish' => false];

    $this->actingAs($this->kepsek)->postJson('/api/v1/pengumuman', $data)->assertJsonPath('data.slug', 'libur-maulid-nabi');
    $this->actingAs($this->kepsek)->postJson('/api/v1/pengumuman', $data)->assertJsonPath('data.slug', 'libur-maulid-nabi-2');
});

it('hanya penulis dan Kepala Sekolah yang bisa mengubah dan menghapus pengumuman', function () {
    $pengumuman = pengumumanUntuk($this->buAini->user, TargetPengumuman::Kelas);
    $pengumuman->kelas()->attach($this->kelasA1);
    $data = ['judul' => 'Ubah', 'isi' => '<p>Isi</p>', 'target' => 'kelas', 'kelas_ids' => [$this->kelasA1->id], 'publish' => true];

    $this->actingAs($this->ibuAisyah->user)->putJson("/api/v1/pengumuman/{$pengumuman->id}", $data)->assertForbidden();
    $this->actingAs($this->buDwi->user)->deleteJson("/api/v1/pengumuman/{$pengumuman->id}")->assertNotFound();
    $this->actingAs($this->buAini->user)->putJson("/api/v1/pengumuman/{$pengumuman->id}", $data)->assertOk();
    $this->actingAs($this->kepsek)->deleteJson("/api/v1/pengumuman/{$pengumuman->id}")->assertOk();

    expect(Pengumuman::query()->count())->toBe(0)
        ->and(Pengumuman::withTrashed()->count())->toBe(1);
});

it('menolak guru lain di feed yang sama mengubah pengumuman Kepala Sekolah', function () {
    $pengumuman = pengumumanUntuk($this->kepsek, TargetPengumuman::Guru);

    $this->actingAs($this->buAini->user)->putJson("/api/v1/pengumuman/{$pengumuman->id}", [
        'judul' => 'Ubah', 'isi' => '<p>Isi</p>', 'target' => 'kelas', 'kelas_ids' => [$this->kelasA1->id], 'publish' => true,
    ])->assertForbidden();
});

it('tidak mengirim notifikasi kalau pengumuman ditarik jadi draft sebelum antrean berjalan', function () {
    $pengumuman = pengumumanUntuk($this->kepsek, TargetPengumuman::Semua, ['published_at' => null]);

    (new KirimNotifikasiPengumuman($pengumuman->id))->handle(app(PengumumanService::class));

    Notification::assertNothingSent();
});
