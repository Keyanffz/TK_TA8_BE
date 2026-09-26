<?php

use App\Enums\JenisAgenda;
use App\Models\Agenda;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    $this->kepsek = buatKepalaSekolah();
});

it('menampilkan agenda yang berlangsung di bulan yang diminta ke semua role, urut tanggal mulai', function () {
    Agenda::factory()->create(['judul' => 'Peringatan Hari Santri', 'tanggal_mulai' => '2026-10-22', 'tanggal_selesai' => '2026-10-22']);
    Agenda::factory()->create(['judul' => 'Kunjungan ke Semarang Zoo', 'tanggal_mulai' => '2026-10-15', 'tanggal_selesai' => '2026-10-15', 'is_publik' => false]);
    Agenda::factory()->create(['judul' => 'Libur semester 1', 'tanggal_mulai' => '2026-12-21', 'tanggal_selesai' => '2027-01-02', 'jenis' => JenisAgenda::Libur]);
    Agenda::factory()->create(['judul' => 'Rapat akhir September', 'tanggal_mulai' => '2026-09-30', 'tanggal_selesai' => '2026-09-30']);

    foreach ([$this->kepsek, buatGuru()->user, User::factory()->waliMurid()->create()] as $user) {
        $this->actingAs($user)->getJson('/api/v1/agenda')
            ->assertOk()
            ->assertJsonPath('meta', null)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.judul', 'Kunjungan ke Semarang Zoo')
            ->assertJsonPath('data.0.tanggal_mulai', '2026-10-15')
            ->assertJsonPath('data.0.is_publik', false)
            ->assertJsonPath('data.1.judul', 'Peringatan Hari Santri');
    }

    $this->actingAs($this->kepsek)->getJson('/api/v1/agenda?bulan=2027-01')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.judul', 'Libur semester 1')
        ->assertJsonPath('data.0.jenis', 'libur');
});

it('memvalidasi format bulan', function () {
    $this->actingAs($this->kepsek)->getJson('/api/v1/agenda?bulan=10-2026')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bulan']);
});

it('membuat, mengubah, dan menghapus agenda sebagai Kepala Sekolah', function () {
    $id = $this->actingAs($this->kepsek)->postJson('/api/v1/agenda', [
        'judul' => 'Pembagian rapor semester 1',
        'deskripsi' => 'Rapor dibagikan di kelas masing-masing pukul 08.00–11.00.',
        'tanggal_mulai' => '2026-12-19',
        'tanggal_selesai' => '2026-12-19',
        'jenis' => 'kegiatan',
        'is_publik' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'Agenda Pembagian rapor semester 1 ditambahkan.')
        ->json('data.id');

    expect(Agenda::query()->findOrFail($id)->dibuat_oleh)->toBe($this->kepsek->id);

    $this->actingAs($this->kepsek)->putJson("/api/v1/agenda/{$id}", [
        'judul' => 'Pembagian rapor semester 1',
        'tanggal_mulai' => '2026-12-19',
        'tanggal_selesai' => '2026-12-20',
        'jenis' => 'kegiatan',
    ])
        ->assertOk()
        ->assertJsonPath('data.tanggal_selesai', '2026-12-20')
        ->assertJsonPath('data.deskripsi', 'Rapor dibagikan di kelas masing-masing pukul 08.00–11.00.');

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/agenda/{$id}")->assertOk();
    expect(Agenda::query()->count())->toBe(0);
});

it('menyimpan agenda tanpa is_publik sebagai agenda internal', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/agenda', [
        'judul' => 'Rapat evaluasi bulanan guru', 'tanggal_mulai' => '2026-10-31', 'tanggal_selesai' => '2026-10-31', 'jenis' => 'rapat',
    ])
        ->assertCreated()
        ->assertJsonPath('data.is_publik', false)
        ->assertJsonPath('data.deskripsi', null);
});

it('menolak tanggal selesai sebelum tanggal mulai dan jenis di luar enum', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/agenda', [
        'judul' => 'Rapat guru',
        'tanggal_mulai' => '2026-10-10',
        'tanggal_selesai' => '2026-10-09',
        'jenis' => 'upacara',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['tanggal_selesai', 'jenis']);
});

it('hanya Kepala Sekolah yang bisa mengelola agenda', function () {
    $agenda = Agenda::factory()->create();
    $data = ['judul' => 'Rapat', 'tanggal_mulai' => '2026-10-10', 'tanggal_selesai' => '2026-10-10', 'jenis' => 'rapat'];

    foreach ([buatGuru()->user, User::factory()->waliMurid()->create()] as $user) {
        $this->actingAs($user)->postJson('/api/v1/agenda', $data)->assertForbidden();
        $this->actingAs($user)->putJson("/api/v1/agenda/{$agenda->id}", $data)->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/v1/agenda/{$agenda->id}")->assertForbidden();
    }
});
