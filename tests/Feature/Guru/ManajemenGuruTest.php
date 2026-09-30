<?php

use App\Enums\StatusAkun;
use App\Models\Guru;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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

it('hanya bisa diakses Kepala Sekolah', function (Closure $akun, string $metode, Closure $path) {
    $akun = $akun();
    $path = $path();
    $jumlahGuru = Guru::query()->count();

    $this->actingAs($akun)->json($metode, $path, dataGuru(['status' => 'nonaktif']))
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');

    expect(Guru::query()->count())->toBe($jumlahGuru)
        ->and(User::query()->where('email', 'nur.aini@gmail.com')->exists())->toBeFalse();
})->with([
    'guru' => [fn () => buatGuru()->user],
    'guru dengan izin keuangan' => [fn () => buatGuru(atributGuru: ['bisa_kelola_keuangan' => true])->user],
    'wali murid' => [fn () => User::factory()->waliMurid()->create()],
])->with([
    'daftar' => ['GET', fn () => '/api/v1/guru'],
    'detail' => ['GET', fn () => '/api/v1/guru/'.test()->kepsek->guru?->id],
    'tambah' => ['POST', fn () => '/api/v1/guru'],
    'ubah' => ['PUT', fn () => '/api/v1/guru/'.buatGuru()->id],
    'ubah status' => ['PATCH', fn () => '/api/v1/guru/'.buatGuru()->id.'/status'],
    'reset tautan Google' => ['POST', fn () => '/api/v1/guru/'.buatGuru(atributGuru: ['user_id' => User::factory()->create(['google_sub' => '109876543210987654321'])])->id.'/reset-google'],
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
    $nonaktif = buatGuru(StatusAkun::Nonaktif);
    buatGuru(atributGuru: ['user_id' => User::factory()->create(['name' => 'Bambang Susilo'])]);
    buatGuru(atributGuru: ['user_id' => User::factory()->create(['name' => 'Citra Lestari']), 'nip' => '198703152010012004']);

    $this->actingAs($this->kepsek)->getJson('/api/v1/guru?filter[status]=nonaktif')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $nonaktif->id);

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
    'status pending yang sudah dihapus' => ['filter[status]=pending', 'filter.status'],
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

it('membuat akun guru aktif tanpa password dengan email Google huruf kecil', function () {
    Storage::fake('public');

    $response = $this->actingAs($this->kepsek)->post('/api/v1/guru', dataGuru([
        'email' => ' Nur.Aini@Gmail.com ',
        'bisa_kelola_keuangan' => '1',
        'foto' => UploadedFile::fake()->image('guru.jpg', 800, 1000),
    ]))
        ->assertCreated()
        ->assertJsonPath('message', 'Akun guru Nur Aini dibuat. Guru bisa masuk dengan akun Google nur.aini@gmail.com.')
        ->assertJsonPath('data.user.email', 'nur.aini@gmail.com')
        ->assertJsonPath('data.user.status', 'aktif')
        ->assertJsonMissingPath('data.password_awal');

    $guru = Guru::query()->with('user')->whereHas('user', fn ($q) => $q->where('email', 'nur.aini@gmail.com'))->sole();

    expect($guru->user->password)->toBeNull()
        ->and($guru->user->google_sub)->toBeNull()
        ->and($guru->bisa_kelola_keuangan)->toBeTrue()
        ->and($response->json('data.foto_url'))->toBe(Storage::disk('public')->url((string) $guru->foto_path));

    $this->postJson('/api/v1/auth/staff/login', ['email' => 'nur.aini@gmail.com', 'password' => 'apaSaja123'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email', ['Email atau password salah.']);
});

it('menolak email guru yang sudah dipakai akun lain tanpa membedakan huruf besar', function (string $email) {
    User::factory()->create(['email' => 'nur.aini@gmail.com']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/guru', dataGuru(['email' => $email]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    expect(User::query()->where('email', 'nur.aini@gmail.com')->count())->toBe(1);
})->with([
    'sama persis' => ['nur.aini@gmail.com'],
    'beda huruf besar' => ['NUR.AINI@gmail.com'],
]);

it('melepas akun Google yang terikat saat email guru diganti', function () {
    $guru = buatGuru(atributGuru: ['user_id' => User::factory()->create(['email' => 'nur.aini@gmail.com', 'google_sub' => '109876543210987654321'])]);

    $this->actingAs($this->kepsek)->putJson("/api/v1/guru/{$guru->id}", dataGuru(['email' => 'nuraini.baru@gmail.com']))
        ->assertOk()
        ->assertJsonPath('data.user.email', 'nuraini.baru@gmail.com');

    expect($guru->user->fresh()?->google_sub)->toBeNull();
});

it('mempertahankan akun Google yang terikat kalau email tidak berubah', function () {
    $guru = buatGuru(atributGuru: ['user_id' => User::factory()->create(['email' => 'nur.aini@gmail.com', 'google_sub' => '109876543210987654321'])]);

    $this->actingAs($this->kepsek)->putJson("/api/v1/guru/{$guru->id}", dataGuru(['email' => 'Nur.Aini@gmail.com', 'name' => 'Nur Aini Fitriani']))->assertOk();

    expect($guru->user->fresh()?->google_sub)->toBe('109876543210987654321');
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

it('menonaktifkan guru, mencabut semua sesinya, dan menolak login Google berikutnya', function () {
    $guru = buatGuru();
    $token = $guru->user->createToken('web')->plainTextToken;
    $guru->user->createToken('mobile');

    $this->actingAs($this->kepsek)->patchJson("/api/v1/guru/{$guru->id}/status", ['status' => 'nonaktif'])
        ->assertOk()
        ->assertJsonPath('message', "Akun {$guru->user->name} sekarang berstatus Nonaktif.")
        ->assertJsonPath('data.user.status', 'nonaktif');

    expect($guru->user->tokens()->count())->toBe(0)
        ->and(Guru::query()->whereKey($guru->id)->exists())->toBeTrue();

    $log = Activity::query()->where('log_name', 'akun')->sole();
    expect($log->properties->all())->toBe(['dari' => 'aktif', 'ke' => 'nonaktif']);

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('mengaktifkan kembali guru nonaktif', function () {
    $guru = buatGuru(StatusAkun::Nonaktif);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/guru/{$guru->id}/status", ['status' => 'aktif'])
        ->assertOk()
        ->assertJsonPath('data.user.status', 'aktif');
});

it('menolak menonaktifkan profil Kepala Sekolah', function () {
    $this->actingAs($this->kepsek)->patchJson("/api/v1/guru/{$this->kepsek->guru?->id}/status", ['status' => 'nonaktif'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Profil Kepala Sekolah tidak bisa dinonaktifkan.');
});

it('hanya menerima status aktif atau nonaktif', function (string $status) {
    $guru = buatGuru();

    $this->actingAs($this->kepsek)->patchJson("/api/v1/guru/{$guru->id}/status", ['status' => $status])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
})->with(['pending', 'ditolak']);

it('tidak menyediakan hapus guru, pendaftaran mandiri, dan persetujuan guru', function (string $metode, Closure $path) {
    $guru = buatGuru();

    $this->actingAs($this->kepsek)->json($metode, $path($guru), ['alasan' => 'Data tidak lengkap.'])
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND');

    expect(Guru::query()->whereKey($guru->id)->exists())->toBeTrue()
        ->and($guru->user->fresh()?->status)->toBe(StatusAkun::Aktif);
})->with([
    'hapus guru' => ['DELETE', fn (Guru $guru) => "/api/v1/guru/{$guru->id}"],
    'daftar guru mandiri' => ['POST', fn (Guru $guru) => '/api/v1/auth/register-guru'],
    'setujui guru' => ['POST', fn (Guru $guru) => "/api/v1/guru/{$guru->id}/setujui"],
    'tolak guru' => ['POST', fn (Guru $guru) => "/api/v1/guru/{$guru->id}/tolak"],
]);

it('mereset tautan Google guru dan mencatat siapa yang melakukannya', function () {
    $guru = buatGuru(atributGuru: ['user_id' => User::factory()->create(['email' => 'nur.aini@gmail.com', 'google_sub' => '109876543210987654321'])]);

    $this->actingAs($this->kepsek)->getJson("/api/v1/guru/{$guru->id}")->assertJsonPath('data.terhubung_google', true);

    $this->postJson("/api/v1/guru/{$guru->id}/reset-google")
        ->assertOk()
        ->assertJsonPath('data.terhubung_google', false)
        ->assertJsonMissingPath('data.user.google_sub');

    $log = Activity::query()->where('log_name', 'akun')->sole();
    expect($guru->user->fresh()?->google_sub)->toBeNull()
        ->and($log->event)->toBe('google_direset')
        ->and($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->subject_id)->toBe($guru->user_id)
        ->and($log->created_at)->not->toBeNull();
});

it('mencabut semua sesi guru saat tautan Google direset sehingga token lama ditolak 401', function () {
    $guru = buatGuru(atributGuru: ['user_id' => User::factory()->create(['google_sub' => '109876543210987654321'])]);
    $tokenWeb = $guru->user->createToken('web')->plainTextToken;
    $tokenMobile = $guru->user->createToken('mobile')->plainTextToken;

    $this->withToken($tokenWeb)->getJson('/api/v1/auth/me')->assertOk();
    $this->app['auth']->forgetGuards();

    $this->actingAs($this->kepsek)->postJson("/api/v1/guru/{$guru->id}/reset-google")->assertOk();
    $this->app['auth']->forgetGuards();

    expect($guru->user->tokens()->count())->toBe(0);
    foreach ([$tokenWeb, $tokenMobile] as $tokenLama) {
        $this->withToken($tokenLama)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->app['auth']->forgetGuards();
    }
});

it('menolak reset tautan Google untuk guru yang belum pernah masuk dengan Google', function () {
    $guru = buatGuru();

    $this->actingAs($this->kepsek)->postJson("/api/v1/guru/{$guru->id}/reset-google")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(Activity::query()->where('log_name', 'akun')->exists())->toBeFalse();
});

it('membiarkan guru masuk dengan akun Google baru beremail sama setelah tautannya direset', function () {
    palsukanGoogle($this);
    $guru = buatGuru(atributGuru: ['user_id' => User::factory()->create(['email' => 'nur.aini@gmail.com', 'google_sub' => '100000000000000000001'])]);
    $akunGoogleBaru = idTokenGoogle(['email' => 'nur.aini@gmail.com', 'sub' => '100000000000000000002']);

    $this->postJson('/api/v1/auth/staff/google', ['credential' => $akunGoogleBaru])->assertStatus(422);

    $this->actingAs($this->kepsek)->postJson("/api/v1/guru/{$guru->id}/reset-google")->assertOk();
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/v1/auth/staff/google', ['credential' => $akunGoogleBaru])
        ->assertOk()
        ->assertJsonPath('data.user.id', $guru->user_id);

    expect($guru->user->fresh()?->google_sub)->toBe('100000000000000000002');
});
