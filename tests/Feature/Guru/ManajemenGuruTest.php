<?php

use App\Enums\StatusAkun;
use App\Models\Guru;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
});

function dataGuru(array $timpa = []): array
{
    return [
        'name' => 'Nur Aini',
        'email' => 'nur.aini@gmail.com',
        'no_hp' => '081390001122',
        'jenis_kelamin' => 'P',
        'nuptk' => '3374012345678901',
        'tanggal_lahir' => '1992-04-17',
        'jabatan' => 'Guru Kelas',
        ...$timpa,
    ];
}

it('hanya bisa diakses Kepala Sekolah', function (Closure $akun) {
    $this->actingAs($akun())->getJson('/api/v1/guru')
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
})->with([
    'guru' => [fn () => buatGuru()->user],
    'guru dengan izin keuangan' => [fn () => buatGuru(atributGuru: ['bisa_kelola_keuangan' => true])->user],
    'wali murid' => [fn () => User::factory()->waliMurid()->create()],
]);

it('menampilkan daftar guru tanpa profil Kepala Sekolah, urut nama', function () {
    buatGuru(atributGuru: ['user_id' => User::factory()->create(['name' => 'Yuni Astuti'])]);
    buatGuru(atributGuru: ['user_id' => User::factory()->create(['name' => 'Ani Rahmawati'])]);

    $this->actingAs($this->kepsek)->getJson('/api/v1/guru')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.user.name', 'Ani Rahmawati')
        ->assertJsonPath('data.1.user.name', 'Yuni Astuti')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonMissingPath('data.0.password_awal');
});

it('memfilter status, mencari, mengurutkan, dan membatasi per halaman', function () {
    $pending = Guru::factory()->menungguPersetujuan()->create();
    buatGuru(atributGuru: ['user_id' => User::factory()->create(['name' => 'Bambang Susilo'])]);
    buatGuru(atributGuru: ['user_id' => User::factory()->create(['name' => 'Citra Lestari']), 'nip' => '198703152010012004']);

    $this->actingAs($this->kepsek)->getJson('/api/v1/guru?filter[status]=pending')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pending->id);

    $this->getJson('/api/v1/guru?search=19870315')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user.name', 'Citra Lestari');

    $this->getJson('/api/v1/guru?sort=-nama&per_page=1&filter[status]=aktif')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user.name', 'Citra Lestari')
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.last_page', 2);
});

it('menolak parameter daftar yang tidak dikenal', function (string $query, string $field) {
    $this->actingAs($this->kepsek)->getJson("/api/v1/guru?{$query}")
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'status asing' => ['filter[status]=cuti', 'filter.status'],
    'urutan asing' => ['sort=email', 'sort'],
    'per_page lebih dari 100' => ['per_page=500', 'per_page'],
]);

it('menampilkan profil guru milik Kepala Sekolah lewat detail', function () {
    $profil = $this->kepsek->guru;

    $this->actingAs($this->kepsek)->getJson("/api/v1/guru/{$profil?->id}")
        ->assertOk()
        ->assertJsonPath('data.user.role', 'super_admin')
        ->assertJsonPath('data.jabatan', 'Kepala Sekolah');
});

it('membalas 404 untuk guru yang tidak ada', function () {
    $this->actingAs($this->kepsek)->getJson('/api/v1/guru/9999')
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND')
        ->assertJsonPath('message', 'Data tidak ditemukan.');
});

it('membuat akun guru aktif dengan password awal yang hanya tampil sekali', function () {
    Storage::fake('public');

    $response = $this->actingAs($this->kepsek)->post('/api/v1/guru', dataGuru([
        'bisa_kelola_keuangan' => '1',
        'foto' => UploadedFile::fake()->image('guru.jpg', 800, 1000),
    ]))->assertCreated();

    $guru = Guru::query()->with('user')->whereHas('user', fn ($q) => $q->where('email', 'nur.aini@gmail.com'))->sole();
    $passwordAwal = $response->json('data.password_awal');

    expect($passwordAwal)->toBeString()->toHaveLength(10)
        ->and(Hash::check($passwordAwal, (string) $guru->user->password))->toBeTrue()
        ->and($guru->user->status)->toBe(StatusAkun::Aktif)
        ->and($guru->disetujui_oleh)->toBe($this->kepsek->id)
        ->and($guru->bisa_kelola_keuangan)->toBeTrue()
        ->and($response->json('data.foto_url'))->toBe(Storage::disk('public')->url((string) $guru->foto_path));

    $this->getJson("/api/v1/guru/{$guru->id}")->assertJsonMissingPath('data.password_awal');

    $this->postJson('/api/v1/auth/staff/login', ['email' => 'nur.aini@gmail.com', 'password' => $passwordAwal])->assertOk();
});

it('menolak email guru yang sudah dipakai akun lain', function () {
    User::factory()->create(['email' => 'nur.aini@gmail.com']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/guru', dataGuru())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('memperbarui data guru dan mencatat perubahan izin keuangan', function () {
    $guru = buatGuru(atributGuru: ['user_id' => User::factory()->create(['email' => 'nur.aini@gmail.com'])]);

    $this->actingAs($this->kepsek)->putJson("/api/v1/guru/{$guru->id}", dataGuru([
        'name' => 'Nur Aini Fitriani',
        'bisa_kelola_keuangan' => true,
        'tampil_di_landing' => true,
    ]))
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Nur Aini Fitriani')
        ->assertJsonPath('data.bisa_kelola_keuangan', true)
        ->assertJsonPath('data.tampil_di_landing', true);

    $log = Activity::query()->where('log_name', 'guru')->sole();
    expect($log->event)->toBe('izin_keuangan_diberikan')
        ->and($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->subject_id)->toBe($guru->id);
});

it('tidak mencatat log izin keuangan kalau izinnya tidak berubah', function () {
    $guru = buatGuru(atributGuru: ['user_id' => User::factory()->create(['email' => 'nur.aini@gmail.com'])]);

    $this->actingAs($this->kepsek)->putJson("/api/v1/guru/{$guru->id}", dataGuru(['bisa_kelola_keuangan' => false]))->assertOk();

    expect(Activity::query()->where('log_name', 'guru')->count())->toBe(0);
});

it('menolak pencabutan izin keuangan profil Kepala Sekolah tetapi data lain boleh diubah', function () {
    $profil = $this->kepsek->guru;
    $data = dataGuru(['name' => $this->kepsek->name, 'email' => $this->kepsek->email, 'jabatan' => 'Kepala Sekolah']);

    $this->actingAs($this->kepsek)->putJson("/api/v1/guru/{$profil?->id}", [...$data, 'bisa_kelola_keuangan' => false])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    $this->putJson("/api/v1/guru/{$profil?->id}", [...$data, 'pendidikan_terakhir' => 'S2 PAUD'])
        ->assertOk()
        ->assertJsonPath('data.pendidikan_terakhir', 'S2 PAUD');
});
