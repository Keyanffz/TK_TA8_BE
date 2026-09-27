<?php

use App\Enums\Hubungan;
use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;

/**
 * Tahun ajaran aktif: Aisyah di TK A1 (wali kelas Bu Aini), Bima di TK B1 (wali kelas Bu Sri).
 * Citra hanya punya kelas di tahun ajaran lalu (juga diampu Bu Aini). Ibu Aisyah hanya tertaut ke Aisyah.
 */
beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
    $aktif = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $lalu = TahunAjaran::factory()->create(['nama' => '2025/2026']);

    $this->buAini = buatGuru();
    $this->buSri = buatGuru();
    $this->kelasA1 = Kelas::factory()->for($aktif)->create(['nama' => 'TK A1', 'tingkat' => 'A', 'wali_kelas_id' => $this->buAini->id]);
    $this->kelasB1 = Kelas::factory()->for($aktif)->create(['nama' => 'TK B1', 'tingkat' => 'B', 'wali_kelas_id' => $this->buSri->id]);
    $kelasLama = Kelas::factory()->for($lalu)->create(['wali_kelas_id' => $this->buAini->id]);

    $this->aisyah = Murid::factory()->create([
        'nama_lengkap' => 'Aisyah Putri Ramadhani', 'nis' => 'TA20260002', 'catatan_khusus' => 'Alergi udang.',
    ]);
    $this->bima = Murid::factory()->create(['nama_lengkap' => 'Bima Saputra', 'nis' => 'TA20250001']);
    $this->citra = Murid::factory()->create(['nama_lengkap' => 'Citra Maharani', 'nis' => 'TA20250007']);
    $this->kelasA1->murid()->attach($this->aisyah);
    $this->kelasB1->murid()->attach($this->bima);
    $kelasLama->murid()->attach($this->citra);

    $this->ibuAisyah = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($this->ibuAisyah, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
});

it('menampilkan semua murid ke Kepala Sekolah, urut nama', function () {
    $this->actingAs($this->kepsek)->getJson('/api/v1/murid')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.nama_lengkap', 'Aisyah Putri Ramadhani')
        ->assertJsonPath('data.0.kelas', ['id' => $this->kelasA1->id, 'nama' => 'TK A1', 'tingkat' => 'A'])
        ->assertJsonPath('data.2.nama_lengkap', 'Citra Maharani')
        ->assertJsonPath('data.2.kelas', null)
        ->assertJsonMissingPath('data.0.wali');
});

it('menampilkan ke guru hanya murid di kelas yang dia ampu pada tahun ajaran aktif', function () {
    $this->actingAs($this->buAini->user)->getJson('/api/v1/murid')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->aisyah->id);
});

it('menampilkan ke wali murid hanya anaknya sendiri', function () {
    $this->actingAs($this->ibuAisyah->user)->getJson('/api/v1/murid')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->aisyah->id);

    $this->actingAs(WaliMurid::factory()->create()->user)->getJson('/api/v1/murid')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('membalas 404 saat guru membuka murid kelas lain', function (string $murid) {
    $this->actingAs($this->buAini->user)->getJson('/api/v1/murid/'.$this->{$murid}->id)
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND')
        ->assertJsonPath('message', 'Data tidak ditemukan.');
})->with(['murid kelas lain' => ['bima'], 'murid kelas tahun ajaran lalu' => ['citra']]);

it('membalas 404 saat wali murid membuka anak orang lain', function () {
    $this->actingAs($this->ibuAisyah->user)->getJson("/api/v1/murid/{$this->bima->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND')
        ->assertJsonPath('message', 'Data tidak ditemukan.');
});

it('membalas 404 yang sama untuk murid yang memang tidak ada', function () {
    $this->actingAs($this->ibuAisyah->user)->getJson('/api/v1/murid/999999')
        ->assertNotFound()
        ->assertJsonPath('message', 'Data tidak ditemukan.');
});

it('menampilkan detail anak ke walinya, termasuk catatan khusus dan wali yang tertaut', function () {
    $this->actingAs($this->ibuAisyah->user)->getJson("/api/v1/murid/{$this->aisyah->id}")
        ->assertOk()
        ->assertJsonPath('data.catatan_khusus', 'Alergi udang.')
        ->assertJsonPath('data.kelas.nama', 'TK A1')
        ->assertJsonPath('data.wali.0.id', $this->ibuAisyah->id)
        ->assertJsonPath('data.wali.0.hubungan', 'ibu')
        ->assertJsonPath('data.wali.0.is_kontak_utama', true);
});

it('menampilkan detail murid dan kontak wali ke guru pengampu', function () {
    $this->actingAs($this->buAini->user)->getJson("/api/v1/murid/{$this->aisyah->id}")
        ->assertOk()
        ->assertJsonPath('data.catatan_khusus', 'Alergi udang.')
        ->assertJsonPath('data.wali.0.no_hp', $this->ibuAisyah->user->no_hp);
});

it('memfilter kelas, status, dan tingkat, mencari, serta mengurutkan murid', function () {
    $this->kelasA1->murid()->attach(
        Murid::factory()->create(['nama_lengkap' => 'Dimas Pratama', 'status' => StatusMurid::Pindah, 'tanggal_keluar' => '2026-08-31']),
        ['status' => StatusKelasMurid::Keluar],
    );
    $this->actingAs($this->kepsek);

    $this->getJson("/api/v1/murid?filter[kelas_id]={$this->kelasA1->id}")->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/murid?filter[status]=pindah')->assertJsonCount(1, 'data')->assertJsonPath('data.0.nama_lengkap', 'Dimas Pratama');
    $this->getJson('/api/v1/murid?filter[tingkat]=B')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->bima->id);
    $this->getJson('/api/v1/murid?search=TA20250007')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->citra->id);
    $this->getJson('/api/v1/murid?sort=nis')->assertJsonPath('data.0.id', $this->bima->id);
});

it('menerapkan filter kelas di dalam jangkauan guru', function () {
    $this->actingAs($this->buAini->user)->getJson("/api/v1/murid?filter[kelas_id]={$this->kelasB1->id}")
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('menolak parameter daftar murid yang tidak dikenal', function (string $query, string $field) {
    $this->actingAs($this->kepsek)->getJson("/api/v1/murid?{$query}")
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'status asing' => ['filter[status]=cuti', 'filter.status'],
    'tingkat asing' => ['filter[tingkat]=C', 'filter.tingkat'],
    'urutan asing' => ['sort=alamat', 'sort'],
]);
