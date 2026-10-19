<?php

use App\Models\Guru;
use App\Models\IzinGuru;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

const TANPA_IZIN = ['keuangan' => null, 'konten_website' => null, 'laporan_absensi' => null, 'data_murid' => null, 'pengumuman' => null];

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
    $this->guru = buatGuru(atributGuru: ['user_id' => User::factory()->create(['name' => 'Siti Rahmawati'])]);
});

it('mendaftar modul, level, dan preset izin yang bisa diberikan', function () {
    $respons = $this->actingAs($this->kepsek)->getJson('/api/v1/izin')->assertOk();

    expect(array_column($respons->json('data.modul'), 'kode'))->toBe(['keuangan', 'konten_website', 'laporan_absensi', 'data_murid', 'pengumuman'])
        ->and(array_column($respons->json('data.level'), 'kode'))->toBe(['lihat', 'kelola'])
        ->and(array_column($respons->json('data.preset'), 'kode'))->toBe(['bendahara', 'admin_website', 'admin_absensi', 'tata_usaha'])
        ->and($respons->json('data.preset.0.izin'))->toBe([...TANPA_IZIN, 'keuangan' => 'kelola'])
        ->and($respons->json('data.preset.1.izin'))->toBe([...TANPA_IZIN, 'konten_website' => 'kelola', 'pengumuman' => 'kelola']);
});

it('mengganti seluruh izin guru dan mencatat sebelum dan sesudahnya', function () {
    $this->guru->izin()->create(['modul' => 'pengumuman', 'level' => 'kelola']);
    $izinBaru = [...TANPA_IZIN, 'keuangan' => 'lihat', 'data_murid' => 'kelola'];

    $this->actingAs($this->kepsek)->putJson("/api/v1/guru/{$this->guru->id}/izin", ['izin' => $izinBaru])
        ->assertOk()
        ->assertJsonPath('message', 'Izin Siti Rahmawati tersimpan.')
        ->assertJsonPath('data.izin', $izinBaru);

    $log = Activity::query()->where('log_name', 'izin')->sole();
    expect(IzinGuru::query()->where('guru_id', $this->guru->id)->count())->toBe(2)
        ->and($log->event)->toBe('diubah')
        ->and($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->subject_id)->toBe($this->guru->id)
        ->and($log->properties->all())->toBe([
            'sebelum' => [...TANPA_IZIN, 'pengumuman' => 'kelola'],
            'sesudah' => $izinBaru,
            'preset' => null,
        ]);
});

it('tidak mencatat log kalau izin yang dikirim sama dengan yang tersimpan', function () {
    $this->actingAs($this->kepsek)->putJson("/api/v1/guru/{$this->guru->id}/izin", ['izin' => TANPA_IZIN])->assertOk();

    expect(Activity::query()->where('log_name', 'izin')->exists())->toBeFalse();
});

it('memvalidasi isian izin guru', function (array $izin, string $field) {
    $this->actingAs($this->kepsek)->putJson("/api/v1/guru/{$this->guru->id}/izin", ['izin' => $izin])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'modul tidak dikirim' => [['keuangan' => 'lihat'], 'izin.pengumuman'],
    'level tidak dikenal' => [[...TANPA_IZIN, 'keuangan' => 'admin'], 'izin.keuangan'],
    'modul tidak dikenal' => [[...TANPA_IZIN, 'guru' => 'kelola'], 'izin'],
]);

it('menerapkan preset dengan mengganti izin lama lalu bisa disesuaikan', function () {
    $this->guru->izin()->create(['modul' => 'data_murid', 'level' => 'kelola']);

    $this->actingAs($this->kepsek)->postJson("/api/v1/guru/{$this->guru->id}/izin/preset", ['preset' => 'bendahara'])
        ->assertOk()
        ->assertJsonPath('message', 'Preset Bendahara diterapkan untuk Siti Rahmawati.')
        ->assertJsonPath('data.izin', [...TANPA_IZIN, 'keuangan' => 'kelola']);

    $log = Activity::query()->where('log_name', 'izin')->sole();
    expect($log->event)->toBe('preset_diterapkan')
        ->and($log->properties['preset'])->toBe('bendahara');

    $this->putJson("/api/v1/guru/{$this->guru->id}/izin", ['izin' => [...TANPA_IZIN, 'keuangan' => 'kelola', 'pengumuman' => 'lihat']])
        ->assertOk()
        ->assertJsonPath('data.izin.pengumuman', 'lihat');
});

it('menolak perubahan izin profil guru milik Kepala Sekolah', function () {
    $profil = $this->kepsek->guru;

    $this->actingAs($this->kepsek)->postJson("/api/v1/guru/{$profil?->id}/izin/preset", ['preset' => 'admin_absensi'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    $this->getJson("/api/v1/guru/{$profil?->id}")
        ->assertOk()
        ->assertJsonPath('data.izin', array_fill_keys(array_keys(TANPA_IZIN), 'kelola'));
});

it('menampilkan izin di daftar guru dan izin efektif di profil guru yang login', function () {
    Guru::factory()->kelolaKeuangan()->create();
    $this->guru->izin()->create(['modul' => 'laporan_absensi', 'level' => 'lihat']);

    $this->actingAs($this->kepsek)->getJson('/api/v1/guru?sort=created_at')
        ->assertOk()
        ->assertJsonPath('data.0.izin', [...TANPA_IZIN, 'laporan_absensi' => 'lihat'])
        ->assertJsonPath('data.1.izin.keuangan', 'kelola');

    $this->actingAs($this->guru->user)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.izin', [...TANPA_IZIN, 'laporan_absensi' => 'lihat'])
        ->assertJsonMissingPath('data.permissions');
});
