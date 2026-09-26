<?php

use App\Models\Guru;
use App\Models\JenisTagihan;
use App\Models\Keringanan;
use App\Models\Murid;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\User;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
    $this->bendahara = Guru::factory()->kelolaKeuangan()->create()->user;
    $this->tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->spp = JenisTagihan::factory()->for($this->tahunAjaran)->create(['nama' => 'SPP', 'nominal' => 150000]);
    $this->murid = Murid::factory()->create(['nama_lengkap' => 'Aisyah Putri']);
});

function dataJenisTagihan(TahunAjaran $tahunAjaran, array $timpa = []): array
{
    return [
        'tahun_ajaran_id' => $tahunAjaran->id,
        'nama' => 'Seragam',
        'deskripsi' => 'Dua stel seragam harian dan satu stel seragam olahraga.',
        'nominal' => 350000,
        'periode' => 'sekali',
        ...$timpa,
    ];
}

it('menampilkan jenis tagihan ke petugas keuangan', function () {
    $this->actingAs($this->bendahara)->getJson('/api/v1/jenis-tagihan?filter[periode]=bulanan')
        ->assertOk()
        ->assertJsonPath('data.0.nama', 'SPP')
        ->assertJsonPath('data.0.nominal', 150000)
        ->assertJsonPath('data.0.tahun_ajaran.nama', '2026/2027');
});

it('menutup jenis tagihan dan keringanan untuk guru tanpa izin keuangan dan wali', function (Closure $akun) {
    $user = $akun();

    $this->actingAs($user)->getJson('/api/v1/jenis-tagihan')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    $this->actingAs($user)->getJson('/api/v1/keringanan')->assertForbidden();
})->with([
    'guru' => [fn () => buatGuru()->user],
    'wali murid' => [fn () => User::factory()->waliMurid()->create()],
]);

it('membuat jenis tagihan sebagai Kepala Sekolah, tidak sebagai guru berizin keuangan', function () {
    $this->actingAs($this->bendahara)->postJson('/api/v1/jenis-tagihan', dataJenisTagihan($this->tahunAjaran))->assertForbidden();

    $this->actingAs($this->kepsek)->postJson('/api/v1/jenis-tagihan', dataJenisTagihan($this->tahunAjaran, ['tingkat' => 'A']))
        ->assertCreated()
        ->assertJsonPath('data.nama', 'Seragam')
        ->assertJsonPath('data.periode', 'sekali')
        ->assertJsonPath('data.tingkat', 'A')
        ->assertJsonPath('data.is_aktif', true);
});

it('memvalidasi isian jenis tagihan', function (array $data, string $field) {
    $this->actingAs($this->kepsek)->postJson('/api/v1/jenis-tagihan', dataJenisTagihan($this->tahunAjaran, $data))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'nominal nol' => [['nominal' => 0], 'nominal'],
    'nominal pecahan' => [['nominal' => 150000.5], 'nominal'],
    'periode asing' => [['periode' => 'tahunan'], 'periode'],
    'tingkat asing' => [['tingkat' => 'C'], 'tingkat'],
]);

it('menolak mengganti periode jenis tagihan yang sudah dipakai, tetapi membolehkan mengubah nominal', function () {
    Tagihan::factory()->for($this->spp)->create();

    $this->actingAs($this->kepsek)->putJson("/api/v1/jenis-tagihan/{$this->spp->id}", dataJenisTagihan($this->tahunAjaran, ['nama' => 'SPP', 'periode' => 'sekali']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    $this->putJson("/api/v1/jenis-tagihan/{$this->spp->id}", dataJenisTagihan($this->tahunAjaran, ['nama' => 'SPP', 'periode' => 'bulanan', 'nominal' => 175000]))
        ->assertOk()
        ->assertJsonPath('data.nominal', 175000);
});

it('menghapus jenis tagihan yang belum dipakai dan menolak yang sudah punya tagihan', function () {
    $seragam = JenisTagihan::factory()->for($this->tahunAjaran)->sekali()->create();
    Tagihan::factory()->for($this->spp)->create();

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/jenis-tagihan/{$seragam->id}")->assertOk();
    $this->deleteJson("/api/v1/jenis-tagihan/{$this->spp->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
});

it('membuat keringanan dan mencatat pembuatnya', function () {
    $this->actingAs($this->bendahara)->postJson('/api/v1/keringanan', [
        'murid_id' => $this->murid->id,
        'jenis_tagihan_id' => $this->spp->id,
        'tipe' => 'persen',
        'nilai' => 50,
        'alasan' => 'Anak yatim.',
        'berlaku_mulai' => '2026-07-01',
    ])
        ->assertCreated()
        ->assertJsonPath('data.murid.nama_lengkap', 'Aisyah Putri')
        ->assertJsonPath('data.jenis_tagihan.nama', 'SPP')
        ->assertJsonPath('data.berlaku_sampai', null)
        ->assertJsonPath('data.dibuat_oleh.id', $this->bendahara->id);
});

it('menolak keringanan yang periodenya bertumpuk untuk murid dan jenis tagihan yang sama', function (string $mulai, ?string $sampai) {
    Keringanan::factory()->for($this->murid)->for($this->spp)->create(['berlaku_mulai' => '2026-07-01', 'berlaku_sampai' => '2026-12-31']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/keringanan', [
        'murid_id' => $this->murid->id,
        'jenis_tagihan_id' => $this->spp->id,
        'tipe' => 'nominal',
        'nilai' => 25000,
        'alasan' => 'Orang tua terdampak PHK.',
        'berlaku_mulai' => $mulai,
        'berlaku_sampai' => $sampai,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
})->with([
    'mulai di tengah periode lama' => ['2026-10-01', null],
    'mencakup seluruh periode lama' => ['2026-06-01', '2027-06-30'],
    'berakhir di dalam periode lama' => ['2026-01-01', '2026-07-01'],
]);

it('membolehkan keringanan baru setelah periode keringanan lama berakhir', function () {
    $lama = Keringanan::factory()->for($this->murid)->for($this->spp)->create(['berlaku_mulai' => '2026-07-01', 'berlaku_sampai' => '2026-12-31']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/keringanan', [
        'murid_id' => $this->murid->id,
        'jenis_tagihan_id' => $this->spp->id,
        'tipe' => 'persen',
        'nilai' => 25,
        'alasan' => 'Perpanjangan dengan potongan lebih kecil.',
        'berlaku_mulai' => '2027-01-01',
    ])->assertCreated();

    $this->putJson("/api/v1/keringanan/{$lama->id}", [
        'murid_id' => $this->murid->id,
        'jenis_tagihan_id' => $this->spp->id,
        'tipe' => 'persen',
        'nilai' => 40,
        'alasan' => 'Anak yatim.',
        'berlaku_mulai' => '2026-07-01',
        'berlaku_sampai' => '2026-12-31',
    ])
        ->assertOk()
        ->assertJsonPath('data.nilai', 40);
});

it('memvalidasi nilai keringanan', function (array $data, string $field) {
    $this->actingAs($this->kepsek)->postJson('/api/v1/keringanan', [
        'murid_id' => $this->murid->id,
        'jenis_tagihan_id' => $this->spp->id,
        'tipe' => 'persen',
        'nilai' => 50,
        'alasan' => 'Anak yatim.',
        'berlaku_mulai' => '2026-07-01',
        ...$data,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'persen lebih dari 100' => [['nilai' => 101], 'nilai'],
    'nilai nol' => [['nilai' => 0], 'nilai'],
    'berakhir sebelum mulai' => [['berlaku_sampai' => '2026-06-30'], 'berlaku_sampai'],
    'tanpa alasan' => [['alasan' => ''], 'alasan'],
]);

it('membolehkan keringanan nominal lebih dari 100', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/keringanan', [
        'murid_id' => $this->murid->id,
        'jenis_tagihan_id' => $this->spp->id,
        'tipe' => 'nominal',
        'nilai' => 50000,
        'alasan' => 'Orang tua terdampak PHK.',
        'berlaku_mulai' => '2026-07-01',
    ])->assertCreated();
});

it('memfilter dan menghapus keringanan', function () {
    $keringanan = Keringanan::factory()->for($this->murid)->for($this->spp)->create();
    Keringanan::factory()->create();

    $this->actingAs($this->bendahara)->getJson("/api/v1/keringanan?filter[murid_id]={$this->murid->id}")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $keringanan->id);
    $this->getJson('/api/v1/keringanan?search=Aisyah')->assertJsonCount(1, 'data');

    $this->deleteJson("/api/v1/keringanan/{$keringanan->id}")->assertOk();
    expect(Keringanan::query()->find($keringanan->id))->toBeNull();
});
