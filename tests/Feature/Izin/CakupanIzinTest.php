<?php

use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Models\Guru;
use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pembayaran;
use App\Models\Pengaturan;
use App\Models\Pengumuman;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Notifications\AbsensiDikoreksiNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * Bu Aini wali kelas TK A1 (Aisyah), Bu Dwi wali kelas TK A2 (Bima). Izin Bu Aini diatur per test, sehingga
 * terlihat data apa saja yang ikut terbuka oleh satu modul izin, dan yang tetap tertutup.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    Notification::fake();
    aturAbsensi(['tanggal_mulai' => '2026-09-01']);

    $this->kepsek = buatKepalaSekolah();
    $this->tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->buAini = buatGuru();
    $this->buDwi = buatGuru();
    $this->buDwi->user->update(['name' => 'Dwi Lestari']);
    $kelasA1 = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id]);
    $kelasA2 = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK A2', 'wali_kelas_id' => $this->buDwi->id]);
    $this->aisyah = Murid::factory()->create();
    $this->bima = Murid::factory()->create();
    $kelasA1->murid()->attach($this->aisyah);
    $kelasA2->murid()->attach($this->bima);
    $this->tagihanBima = Tagihan::factory()->for($this->bima)->create();
});

function beriIzin(Guru $guru, string $modul, string $level): User
{
    $guru->izin()->create(['modul' => $modul, 'level' => $level]);

    return $guru->user->fresh() ?? throw new LogicException('Akun guru hilang.');
}

it('membuka semua murid untuk izin lihat data murid tanpa membuka tagihannya', function () {
    $tanpaIzin = $this->actingAs($this->buAini->user)->getJson('/api/v1/murid')->assertOk()->json('data.*.id');
    expect($tanpaIzin)->toBe([$this->aisyah->id]);
    $this->getJson("/api/v1/murid/{$this->bima->id}")->assertNotFound();

    $guru = beriIzin($this->buAini, 'data_murid', 'lihat');

    expect($this->actingAs($guru)->getJson('/api/v1/murid')->assertOk()->json('meta.total'))->toBe(2);
    $this->getJson("/api/v1/murid/{$this->bima->id}")->assertOk();
    $this->getJson("/api/v1/tagihan/{$this->tagihanBima->id}")->assertNotFound();
});

it('membuka semua tagihan dan pembayaran untuk izin lihat keuangan tanpa boleh memverifikasi', function () {
    $pembayaran = Pembayaran::factory()->for($this->tagihanBima)->create();
    $guru = beriIzin($this->buAini, 'keuangan', 'lihat');

    $this->actingAs($guru)->getJson("/api/v1/tagihan/{$this->tagihanBima->id}")->assertOk();
    $this->getJson('/api/v1/pembayaran')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/pengaturan?grup=keuangan')->assertOk();
    $this->postJson("/api/v1/pembayaran/{$pembayaran->id}/terima")->assertForbidden();
    $this->postJson("/api/v1/tagihan/{$this->tagihanBima->id}/pembayaran", ['metode' => 'tunai', 'tanggal_bayar' => '2026-10-02'])->assertForbidden();
});

it('mencatat perubahan keringanan oleh guru berizin kelola keuangan di log aktivitas', function () {
    $guru = beriIzin($this->buAini, 'keuangan', 'kelola');
    $spp = JenisTagihan::factory()->for($this->tahunAjaran)->create(['nama' => 'SPP']);

    $id = $this->actingAs($guru)->postJson('/api/v1/keringanan', [
        'murid_id' => $this->bima->id, 'jenis_tagihan_id' => $spp->id, 'tipe' => 'persen', 'nilai' => 50,
        'alasan' => 'Anak guru', 'berlaku_mulai' => '2026-10-01',
    ])->assertCreated()->json('data.id');
    $this->deleteJson("/api/v1/keringanan/{$id}")->assertOk();

    $log = Activity::query()->where('log_name', 'keringanan')->orderBy('id')->get();
    expect($log->pluck('event')->all())->toBe(['dibuat', 'dihapus'])
        ->and($log->pluck('causer_id')->unique()->all())->toBe([$guru->id])
        ->and($log->first()?->description)->toBe("Menambah keringanan SPP untuk {$this->bima->nama_lengkap}");
});

it('membatasi izin kelola konten website ke grup profil, landing, dan beranda', function () {
    $guru = beriIzin($this->buAini, 'konten_website', 'kelola');

    $this->actingAs($guru)->putJson('/api/v1/pengaturan', ['items' => ['profil.visi' => 'Anak saleh, ceria, dan mandiri.']])->assertOk();
    expect($this->getJson('/api/v1/pengaturan?grup=profil')->assertOk()->json('data')['profil.visi'])->toBe('Anak saleh, ceria, dan mandiri.');
    $this->getJson('/api/v1/pengaturan?grup=ppdb')->assertForbidden();

    $this->putJson('/api/v1/pengaturan', ['items' => ['profil.visi' => 'Visi lain', 'absensi.radius_meter' => 500]])
        ->assertForbidden()
        ->assertJsonPath('message', 'Bagian ini khusus Kepala Sekolah.');

    expect(Pengaturan::query()->where('kunci', 'profil.visi')->sole()->nilai)->toBe('Anak saleh, ceria, dan mandiri.')
        ->and(Pengaturan::query()->where('kunci', 'absensi.radius_meter')->sole()->nilai)->toBe(100);
});

it('membolehkan izin kelola pengumuman memakai target apa pun dan mengubah pengumuman orang lain', function () {
    $isian = ['judul' => 'Libur Maulid Nabi', 'isi' => '<p>Sekolah libur.</p>', 'target' => 'semua', 'is_publik' => true, 'publish' => false];
    $this->actingAs($this->buAini->user)->postJson('/api/v1/pengumuman', $isian)->assertStatus(422)->assertJsonValidationErrors(['target']);

    $guru = beriIzin($this->buAini, 'pengumuman', 'kelola');
    $milikKepsek = Pengumuman::factory()->create(['penulis_id' => $this->kepsek->id]);

    $this->actingAs($guru)->postJson('/api/v1/pengumuman', $isian)->assertCreated()->assertJsonPath('data.is_publik', true);
    $this->putJson("/api/v1/pengumuman/{$milikKepsek->id}", [...$isian, 'judul' => 'Jadwal libur diperbarui'])->assertOk();
});

it('membolehkan izin kelola laporan absensi mengoreksi peserta lain tetapi tidak absensinya sendiri', function () {
    $guru = beriIzin($this->buAini, 'laporan_absensi', 'kelola');
    $milikDwi = Absensi::factory()->for($this->buDwi->user)->tidakHadir()->create(['tanggal' => '2026-10-01']);
    $milikSendiri = Absensi::factory()->for($guru)->tidakHadir()->create(['tanggal' => '2026-10-01']);

    $this->actingAs($guru)->getJson("/api/v1/absensi?user_id={$this->buDwi->user_id}")->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson("/api/v1/absensi/{$milikDwi->id}/koreksi", ['status' => 'hadir', 'catatan' => 'Ikut rapat di dinas.'])->assertOk();
    $this->patchJson("/api/v1/absensi/{$milikSendiri->id}/koreksi", ['status' => 'hadir', 'catatan' => 'Lupa absen.'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Absensi Anda sendiri hanya bisa dikoreksi atau dicatat Kepala Sekolah.');

    expect($milikDwi->fresh()?->status)->toBe(StatusAbsensi::Hadir)
        ->and($milikSendiri->fresh()?->status)->toBe(StatusAbsensi::TidakHadir);
    Notification::assertSentTo($this->buDwi->user, AbsensiDikoreksiNotification::class, fn (AbsensiDikoreksiNotification $notifikasi) => $notifikasi->toDatabase($this->buDwi->user)['judul'] === "Absensi dikoreksi {$guru->name}");
});
