<?php

use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Models\Kelas;
use App\Models\KelasMurid;
use App\Models\Murid;
use App\Models\TahunAjaran;

/**
 * Tahun ajaran aktif 2026/2027: Aisyah dan Bima di TK A1, Citra di TK B1.
 * Tahun ajaran tujuan 2027/2028: TK B1 baru (kapasitas 2) dan TK A1 baru.
 */
beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
    $this->aktif = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027', 'tanggal_selesai' => '2027-06-25']);
    $this->tujuan = TahunAjaran::factory()->create(['nama' => '2027/2028']);

    $this->a1 = Kelas::factory()->for($this->aktif)->create(['nama' => 'TK A1', 'tingkat' => 'A']);
    $this->b1 = Kelas::factory()->for($this->aktif)->create(['nama' => 'TK B1', 'tingkat' => 'B']);
    $this->b1Baru = Kelas::factory()->for($this->tujuan)->create(['nama' => 'TK B1', 'tingkat' => 'B', 'kapasitas' => 2]);
    $this->a1Baru = Kelas::factory()->for($this->tujuan)->create(['nama' => 'TK A1', 'tingkat' => 'A']);

    $this->aisyah = Murid::factory()->create();
    $this->bima = Murid::factory()->create();
    $this->citra = Murid::factory()->create();
    $this->a1->murid()->attach([$this->aisyah->id, $this->bima->id]);
    $this->b1->murid()->attach($this->citra);
});

function statusPenempatan(Kelas $kelas, Murid $murid): ?StatusKelasMurid
{
    return KelasMurid::query()->where('kelas_id', $kelas->id)->where('murid_id', $murid->id)->first()?->status;
}

it('menaikkan, meninggalkan, dan meluluskan murid dalam satu permintaan', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/kelas/kenaikan', [
        'tahun_ajaran_tujuan_id' => $this->tujuan->id,
        'penempatan' => [
            ['murid_id' => $this->aisyah->id, 'kelas_tujuan_id' => $this->b1Baru->id, 'status' => 'naik'],
            ['murid_id' => $this->bima->id, 'kelas_tujuan_id' => $this->a1Baru->id, 'status' => 'tinggal'],
            ['murid_id' => $this->citra->id, 'kelas_tujuan_id' => null, 'status' => 'lulus'],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data', ['naik' => 1, 'tinggal' => 1, 'lulus' => 1])
        ->assertJsonPath('message', 'Kenaikan kelas ke 2027/2028 tersimpan: 1 naik, 1 tinggal kelas, 1 lulus.');

    expect(statusPenempatan($this->a1, $this->aisyah))->toBe(StatusKelasMurid::Naik)
        ->and(statusPenempatan($this->b1Baru, $this->aisyah))->toBe(StatusKelasMurid::Aktif)
        ->and(statusPenempatan($this->a1, $this->bima))->toBe(StatusKelasMurid::Tinggal)
        ->and(statusPenempatan($this->a1Baru, $this->bima))->toBe(StatusKelasMurid::Aktif)
        ->and(statusPenempatan($this->b1, $this->citra))->toBe(StatusKelasMurid::Lulus)
        ->and($this->citra->fresh()?->status)->toBe(StatusMurid::Lulus)
        ->and($this->citra->fresh()?->tanggal_keluar?->toDateString())->toBe('2027-06-25')
        ->and($this->aisyah->fresh()?->status)->toBe(StatusMurid::Aktif);
});

it('membatalkan seluruh kenaikan kalau satu kelas tujuan melebihi kapasitas', function () {
    $this->b1Baru->murid()->attach(Murid::factory()->create());

    $this->actingAs($this->kepsek)->postJson('/api/v1/kelas/kenaikan', [
        'tahun_ajaran_tujuan_id' => $this->tujuan->id,
        'penempatan' => [
            ['murid_id' => $this->aisyah->id, 'kelas_tujuan_id' => $this->b1Baru->id, 'status' => 'naik'],
            ['murid_id' => $this->bima->id, 'kelas_tujuan_id' => $this->b1Baru->id, 'status' => 'naik'],
            ['murid_id' => $this->citra->id, 'status' => 'lulus'],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(statusPenempatan($this->a1, $this->aisyah))->toBe(StatusKelasMurid::Aktif)
        ->and(statusPenempatan($this->b1, $this->citra))->toBe(StatusKelasMurid::Aktif)
        ->and($this->citra->fresh()?->status)->toBe(StatusMurid::Aktif);
});

it('menolak kenaikan yang melanggar aturan', function (Closure $permintaan) {
    $this->actingAs($this->kepsek)->postJson('/api/v1/kelas/kenaikan', $permintaan->call($this))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(KelasMurid::query()->where('status', '!=', StatusKelasMurid::Aktif)->count())->toBe(0);
})->with([
    'tahun ajaran tujuan sama dengan yang aktif' => [fn () => [
        'tahun_ajaran_tujuan_id' => $this->aktif->id,
        'penempatan' => [['murid_id' => $this->aisyah->id, 'kelas_tujuan_id' => $this->b1->id, 'status' => 'naik']],
    ]],
    'kelas tujuan bukan di tahun ajaran tujuan' => [fn () => [
        'tahun_ajaran_tujuan_id' => $this->tujuan->id,
        'penempatan' => [['murid_id' => $this->aisyah->id, 'kelas_tujuan_id' => $this->b1->id, 'status' => 'naik']],
    ]],
    'murid tanpa kelas di tahun ajaran aktif' => [fn () => [
        'tahun_ajaran_tujuan_id' => $this->tujuan->id,
        'penempatan' => [['murid_id' => Murid::factory()->create()->id, 'kelas_tujuan_id' => $this->b1Baru->id, 'status' => 'naik']],
    ]],
    'murid sudah punya kelas di tahun ajaran tujuan' => [function () {
        $this->a1Baru->murid()->attach($this->aisyah);

        return [
            'tahun_ajaran_tujuan_id' => $this->tujuan->id,
            'penempatan' => [['murid_id' => $this->aisyah->id, 'kelas_tujuan_id' => $this->b1Baru->id, 'status' => 'naik']],
        ];
    }],
]);

it('memvalidasi isian kenaikan kelas', function (array $penempatan, string $field) {
    $this->actingAs($this->kepsek)->postJson('/api/v1/kelas/kenaikan', [
        'tahun_ajaran_tujuan_id' => $this->tujuan->id,
        'penempatan' => [['murid_id' => $this->aisyah->id, ...$penempatan]],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'naik tanpa kelas tujuan' => [['status' => 'naik'], 'penempatan.0.kelas_tujuan_id'],
    'lulus dengan kelas tujuan' => [['status' => 'lulus', 'kelas_tujuan_id' => 1], 'penempatan.0.kelas_tujuan_id'],
    'status keluar tidak dipakai di kenaikan' => [['status' => 'keluar'], 'penempatan.0.status'],
]);
