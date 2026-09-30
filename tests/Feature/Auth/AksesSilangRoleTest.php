<?php

use App\Enums\Role;
use App\Models\User;
use App\Models\WaliMurid;
use Illuminate\Routing\Route as RouteLaravel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

function tokenWali(TestCase $test): string
{
    $user = User::factory()->waliMurid()->create(['username' => 'TA20260041', 'password' => 'melatiPagi26']);
    WaliMurid::factory()->for($user)->create();

    return $test->postJson('/api/v1/auth/wali/login', ['username' => 'TA20260041', 'password' => 'melatiPagi26'])
        ->assertOk()
        ->json('data.token');
}

/**
 * Guru hanya bisa login lewat Google; Kepala Sekolah lewat password.
 */
function tokenStaff(TestCase $test, User $user): string
{
    if ($user->role === Role::Guru) {
        palsukanGoogle($test);

        return $test->postJson('/api/v1/auth/staff/google', ['credential' => idTokenGoogle(['email' => $user->email])])
            ->assertOk()
            ->json('data.token');
    }

    $user->update(['password' => 'ruangGuru26']);

    return $test->postJson('/api/v1/auth/staff/login', ['email' => $user->email, 'password' => 'ruangGuru26'])
        ->assertOk()
        ->json('data.token');
}

it('menolak token wali murid di endpoint staff dengan 403', function (string $metode, string $url) {
    $this->withToken(tokenWali($this))->json($metode, $url)
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
})->with([
    'daftar guru' => ['GET', '/api/v1/guru'],
    'daftar wali murid' => ['GET', '/api/v1/wali-murid'],
    'tahun ajaran' => ['GET', '/api/v1/tahun-ajaran'],
    'daftar kelas' => ['GET', '/api/v1/kelas'],
    'tambah murid' => ['POST', '/api/v1/murid'],
    'elemen penilaian' => ['GET', '/api/v1/elemen-penilaian'],
    'tambah kegiatan' => ['POST', '/api/v1/kegiatan'],
    'buat rapor' => ['POST', '/api/v1/rapor'],
    'buat pengumuman' => ['POST', '/api/v1/pengumuman'],
    'generate tagihan' => ['POST', '/api/v1/tagihan/generate'],
    'pengaturan' => ['GET', '/api/v1/pengaturan'],
    'laporan keuangan' => ['GET', '/api/v1/laporan/keuangan'],
    'keringanan' => ['GET', '/api/v1/keringanan'],
    'log aktivitas' => ['GET', '/api/v1/log-aktivitas'],
]);

it('menolak token guru dan Kepala Sekolah di endpoint wali murid dengan 403', function (Closure $staff, string $metode, string $url) {
    $this->withToken(tokenStaff($this, $staff()))->json($metode, $url)
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
})->with([
    'guru' => [fn () => buatGuru()->user],
    'Kepala Sekolah' => [fn () => buatKepalaSekolah()],
])->with([
    'profil wali' => ['PUT', '/api/v1/wali/profil'],
    'tambah anak' => ['POST', '/api/v1/wali/tambah-anak'],
    'daftar anak' => ['GET', '/api/v1/wali/anak'],
    'daftar PPDB kakak/adik' => ['POST', '/api/v1/pendaftaran'],
]);

it('menolak token guru di endpoint khusus Kepala Sekolah dengan 403', function (string $metode, string $url) {
    $this->withToken(tokenStaff($this, buatGuru()->user))->json($metode, $url)
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
})->with([
    'daftar guru' => ['GET', '/api/v1/guru'],
    'daftar wali murid' => ['GET', '/api/v1/wali-murid'],
    'daftar PPDB' => ['GET', '/api/v1/pendaftaran'],
    'log aktivitas' => ['GET', '/api/v1/log-aktivitas'],
]);

/*
 * Route login tanpa middleware `role:`/`can:` dipakai semua role dengan data yang dibatasi scope `visibleTo` dan
 * Policy (A7 "Scoping data otomatis"). Route login baru yang tidak dibatasi role harus ditambahkan ke daftar ini
 * dengan sengaja, supaya tidak ada endpoint staff atau wali yang terbuka untuk role lain karena lupa middleware.
 */
it('membatasi role di setiap route login kecuali route bersama yang dipakai semua role', function () {
    $routeBersama = [
        'GET api/v1/auth/me', 'POST api/v1/auth/logout', 'PUT api/v1/auth/profil',
        'GET api/v1/dashboard',
        'GET api/v1/murid', 'GET api/v1/murid/{id}',
        'GET api/v1/tagihan', 'GET api/v1/tagihan/{id}', 'POST api/v1/tagihan/{id}/pembayaran',
        'GET api/v1/pembayaran', 'GET api/v1/pembayaran/{id}', 'GET api/v1/pembayaran/{id}/bukti', 'GET api/v1/pembayaran/{id}/kwitansi',
        'GET api/v1/kegiatan', 'GET api/v1/kegiatan/{id}',
        'GET api/v1/rapor', 'GET api/v1/rapor/{id}', 'GET api/v1/rapor/{id}/pdf',
        'GET api/v1/pengumuman', 'GET api/v1/pengumuman/{id}',
        'GET api/v1/agenda',
        'GET api/v1/notifikasi', 'GET api/v1/notifikasi/belum-dibaca', 'POST api/v1/notifikasi/baca-semua', 'POST api/v1/notifikasi/{id}/baca',
    ];

    $tanpaBatasRole = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteLaravel $route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->reject(fn (RouteLaravel $route) => collect($route->gatherMiddleware())
            ->contains(fn (mixed $middleware) => is_string($middleware) && Str::startsWith($middleware, ['role:', 'can:'])))
        ->flatMap(fn (RouteLaravel $route) => collect($route->methods())
            ->reject(fn (string $metode) => $metode === 'HEAD')
            ->map(fn (string $metode) => "{$metode} {$route->uri()}"))
        ->values()
        ->all();

    expect($tanpaBatasRole)->toEqualCanonicalizing($routeBersama);
});
