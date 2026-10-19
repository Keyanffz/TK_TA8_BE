<?php

use App\Enums\Hubungan;
use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Enums\TargetPengumuman;
use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\KelasMurid;
use App\Models\Murid;
use App\Models\Pengaturan;
use App\Models\Pengumuman;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use App\Services\PengumumanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * Tahun ajaran aktif 2026/2027. TK B1 berisi Aisyah dan Bima (masuk 13 Juli 2025), TK A1 berisi Citra.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-05 09:00:00');
    Notification::fake();

    $this->kepsek = buatKepalaSekolah();
    $this->tahunAjaran = TahunAjaran::factory()->aktif()->create([
        'nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-25',
    ]);
    $this->kelasB1 = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK B1', 'tingkat' => 'B', 'kapasitas' => 2]);
    $this->kelasA1 = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK A1', 'tingkat' => 'A']);
    $this->aisyah = Murid::factory()->create(['nama_lengkap' => 'Aisyah Putri', 'tanggal_masuk' => '2025-07-14']);
    $this->bima = Murid::factory()->create(['nama_lengkap' => 'Bima Saputra', 'tanggal_masuk' => '2025-07-14']);
    $this->citra = Murid::factory()->create(['nama_lengkap' => 'Citra Lestari']);
    $this->kelasB1->murid()->attach([$this->aisyah->id, $this->bima->id]);
    $this->kelasA1->murid()->attach($this->citra);
});

function penempatan(Murid $murid): ?StatusKelasMurid
{
    return KelasMurid::query()->where('murid_id', $murid->id)->value('status');
}

it('memindahkan murid, menutup penempatan kelasnya, dan mencatat riwayat status', function () {
    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$this->aisyah->id}/status", [
        'status' => 'pindah',
        'tanggal' => '2026-09-30',
        'sekolah_tujuan' => 'TK Pertiwi 3 Semarang',
        'keterangan' => 'Ikut orang tua pindah tugas.',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Status Aisyah Putri sekarang Pindah.')
        ->assertJsonPath('data.status', 'pindah')
        ->assertJsonPath('data.tanggal_keluar', '2026-09-30')
        ->assertJsonPath('data.sekolah_tujuan', 'TK Pertiwi 3 Semarang')
        ->assertJsonPath('data.keterangan_status', 'Ikut orang tua pindah tugas.')
        ->assertJsonPath('data.tahun_lulus', null)
        ->assertJsonPath('data.riwayat_status.0.status_lama', 'aktif')
        ->assertJsonPath('data.riwayat_status.0.status_baru', 'pindah')
        ->assertJsonPath('data.riwayat_status.0.tanggal', '2026-09-30')
        ->assertJsonPath('data.riwayat_status.0.diubah_oleh.id', $this->kepsek->id);

    expect(penempatan($this->aisyah))->toBe(StatusKelasMurid::Keluar)
        ->and($this->kelasB1->muridAktif()->count())->toBe(1)
        ->and(Activity::query()->where('log_name', 'murid')->sole()->event)->toBe('status_diubah');
});

it('mengaktifkan kembali murid dan membuka penempatannya kalau kelas masih muat', function () {
    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$this->aisyah->id}/status", ['status' => 'keluar', 'tanggal' => '2026-09-01'])->assertOk();

    $this->patchJson("/api/v1/murid/{$this->aisyah->id}/status", ['status' => 'aktif', 'tanggal' => '2026-10-05', 'keterangan' => 'Kembali bersekolah.'])
        ->assertOk()
        ->assertJsonPath('data.tanggal_keluar', null)
        ->assertJsonPath('data.keterangan_status', null)
        ->assertJsonCount(2, 'data.riwayat_status')
        ->assertJsonPath('data.riwayat_status.0.keterangan', 'Kembali bersekolah.');

    expect(penempatan($this->aisyah))->toBe(StatusKelasMurid::Aktif);
});

it('menolak mengaktifkan kembali murid kalau kelasnya sudah penuh', function () {
    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$this->aisyah->id}/status", ['status' => 'keluar', 'tanggal' => '2026-09-01'])->assertOk();
    $pengganti = Murid::factory()->create();
    $this->kelasB1->murid()->attach($pengganti);

    $this->patchJson("/api/v1/murid/{$this->aisyah->id}/status", ['status' => 'aktif', 'tanggal' => '2026-10-05'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect($this->aisyah->fresh()?->status)->toBe(StatusMurid::Keluar)
        ->and($this->aisyah->riwayatStatus()->count())->toBe(1);
});

it('memvalidasi isian ubah status', function (array $isian, string $field) {
    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$this->aisyah->id}/status", $isian)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'tahun lulus kosong untuk lulus' => [['status' => 'lulus', 'tanggal' => '2026-06-25'], 'tahun_lulus'],
    'tahun lulus untuk murid pindah' => [['status' => 'pindah', 'tanggal' => '2026-09-30', 'tahun_lulus' => 2026], 'tahun_lulus'],
    'sekolah tujuan untuk murid keluar' => [['status' => 'keluar', 'tanggal' => '2026-09-30', 'sekolah_tujuan' => 'TK Pertiwi'], 'sekolah_tujuan'],
    'tanggal di masa depan' => [['status' => 'keluar', 'tanggal' => '2026-10-06'], 'tanggal'],
    'status tidak dikenal' => [['status' => 'cuti', 'tanggal' => '2026-09-30'], 'status'],
]);

it('menolak status yang sama dan tanggal sebelum tanggal masuk', function (array $isian, string $pesan) {
    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$this->aisyah->id}/status", $isian)
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', $pesan);
})->with([
    'status sama' => [['status' => 'aktif', 'tanggal' => '2026-10-01'], 'Aisyah Putri sudah berstatus Aktif.'],
    'sebelum tanggal masuk' => [['status' => 'keluar', 'tanggal' => '2025-07-01'], 'Tanggal status Aisyah Putri tidak boleh sebelum tanggal masuknya (14 Juli 2025).'],
    'tahun lulus sebelum tahun masuk' => [['status' => 'lulus', 'tanggal' => '2026-06-20', 'tahun_lulus' => 2024], 'Tahun lulus Aisyah Putri tidak boleh sebelum tahun masuknya (2025).'],
]);

it('meluluskan satu kelas sekaligus untuk tahun tertentu', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/murid/status-massal', [
        'kelas_id' => $this->kelasB1->id, 'status' => 'lulus', 'tanggal' => '2026-06-20', 'tahun_lulus' => 2026,
    ])
        ->assertOk()
        ->assertJsonPath('data', ['diubah' => 2, 'dilewati' => 0]);

    expect(Murid::query()->where('status', StatusMurid::Lulus)->where('tahun_lulus', 2026)->pluck('id')->sort()->values()->all())
        ->toBe([$this->aisyah->id, $this->bima->id])
        ->and(penempatan($this->bima))->toBe(StatusKelasMurid::Lulus)
        ->and($this->citra->fresh()?->status)->toBe(StatusMurid::Aktif)
        ->and(Activity::query()->where('event', 'status_diubah_massal')->sole()->properties['diubah'])->toBe(2);
});

it('mengubah status murid terpilih dan melewati yang statusnya sudah sama', function () {
    $this->bima->update(['status' => StatusMurid::Keluar, 'tanggal_keluar' => '2026-08-01']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/murid/status-massal', [
        'murid_ids' => [$this->aisyah->id, $this->bima->id], 'status' => 'keluar', 'tanggal' => '2026-10-01',
    ])
        ->assertOk()
        ->assertJsonPath('data', ['diubah' => 1, 'dilewati' => 1]);

    expect($this->bima->fresh()?->tanggal_keluar?->toDateString())->toBe('2026-08-01');
});

it('menolak ubah status massal tanpa murid atau kelas, dan kelas tanpa murid aktif', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/murid/status-massal', ['status' => 'lulus', 'tanggal' => '2026-06-20', 'tahun_lulus' => 2026])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['murid_ids', 'kelas_id']);

    $kosong = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK B2']);
    $this->postJson('/api/v1/murid/status-massal', ['kelas_id' => $kosong->id, 'status' => 'lulus', 'tanggal' => '2026-06-20', 'tahun_lulus' => 2026])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Tidak ada murid aktif di TK B2.');
});

it('tidak membuat tagihan bulanan untuk murid yang lulus, tetapi tagihan lamanya tetap', function () {
    Pengaturan::query()->create(['kunci' => 'keuangan.tanggal_jatuh_tempo', 'nilai' => 10, 'grup' => 'keuangan']);
    $spp = JenisTagihan::factory()->for($this->tahunAjaran)->create(['nama' => 'SPP', 'nominal' => 150000]);
    $tagihanLama = Tagihan::factory()->for($this->aisyah)->create(['jenis_tagihan_id' => $spp->id, 'periode' => '2026-09-01']);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$this->aisyah->id}/status", ['status' => 'lulus', 'tanggal' => '2026-10-01', 'tahun_lulus' => 2026])->assertOk();
    $this->postJson('/api/v1/tagihan/generate', ['periode' => '2026-11'])->assertOk();

    expect(Tagihan::query()->where('murid_id', $this->aisyah->id)->pluck('id')->all())->toBe([$tagihanLama->id])
        ->and(Tagihan::query()->where('murid_id', $this->bima->id)->count())->toBe(1);
});

it('mencatat tahun lulus dan riwayat status untuk murid yang lulus lewat kenaikan kelas', function () {
    $tujuan = TahunAjaran::factory()->create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-23']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/kelas/kenaikan', [
        'tahun_ajaran_tujuan_id' => $tujuan->id,
        'penempatan' => [['murid_id' => $this->aisyah->id, 'kelas_tujuan_id' => null, 'status' => 'lulus']],
    ])->assertOk();

    $aisyah = $this->aisyah->fresh();
    expect($aisyah?->status)->toBe(StatusMurid::Lulus)
        ->and($aisyah?->tahun_lulus)->toBe(2027)
        ->and($aisyah?->tanggal_keluar?->toDateString())->toBe('2027-06-25')
        ->and($aisyah?->riwayatStatus()->sole()->diubah_oleh)->toBe($this->kepsek->id);
});

it('mendaftar alumni per tahun lulus dengan pencarian', function () {
    Murid::factory()->create(['nama_lengkap' => 'Dimas Pratama', 'status' => StatusMurid::Lulus, 'tahun_lulus' => 2019, 'tanggal_keluar' => '2019-06-29']);
    Murid::factory()->create(['nama_lengkap' => 'Dewi Anggraini', 'status' => StatusMurid::Lulus, 'tahun_lulus' => 2021]);
    Murid::factory()->create(['nama_lengkap' => 'Doni Kurniawan', 'status' => StatusMurid::Lulus, 'tahun_lulus' => 2019]);

    $this->actingAs($this->kepsek)->getJson('/api/v1/murid/tahun-lulus')->assertOk()->assertJsonPath('data', [2021, 2019]);

    $this->getJson('/api/v1/murid?filter[status]=lulus&filter[tahun_lulus]=2019&search=Dimas')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.nama_lengkap', 'Dimas Pratama')
        ->assertJsonPath('data.0.tahun_lulus', 2019)
        ->assertJsonPath('data.0.kelas', null);
});

it('menghentikan pengumuman umum untuk wali yang semua anaknya tidak aktif lagi, tanpa menonaktifkan akunnya', function () {
    $wali = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($wali, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $umum = Pengumuman::factory()->create(['penulis_id' => $this->kepsek->id, 'target' => TargetPengumuman::Semua]);
    $untukAisyah = Pengumuman::factory()->create(['penulis_id' => $this->kepsek->id, 'target' => TargetPengumuman::Murid]);
    $untukAisyah->murid()->attach($this->aisyah);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$this->aisyah->id}/status", ['status' => 'lulus', 'tanggal' => '2026-10-01', 'tahun_lulus' => 2026])->assertOk();

    $feed = $this->actingAs($wali->user)->getJson('/api/v1/pengumuman')->assertOk()->json('data.*.id');
    $penerima = fn (Pengumuman $pengumuman) => app(PengumumanService::class)->penerima($pengumuman)->pluck('id')->all();

    expect($feed)->toBe([$untukAisyah->id])
        ->and($penerima($umum))->not->toContain($wali->user_id)
        ->and($penerima($untukAisyah))->toContain($wali->user_id);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.status', 'aktif')
        ->assertJsonPath('data.wali_murid.anak.0.status', 'lulus');
});
