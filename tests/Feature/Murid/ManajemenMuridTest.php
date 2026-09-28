<?php

use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Models\Kelas;
use App\Models\KelasMurid;
use App\Models\Murid;
use App\Models\Rapor;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
});

function dataMurid(array $timpa = []): array
{
    return [
        'nama_lengkap' => 'Aisyah Putri Ramadhani',
        'nama_panggilan' => 'Aisyah',
        'jenis_kelamin' => 'P',
        'tempat_lahir' => 'Semarang',
        'tanggal_lahir' => '2022-03-14',
        'agama' => 'Islam',
        'alamat' => 'Jl. Kedungmundu Raya No. 21, Tembalang, Semarang',
        'anak_ke' => 2,
        'nik' => '3374015403220001',
        'catatan_khusus' => 'Alergi udang.',
        'tanggal_masuk' => '2026-07-13',
        ...$timpa,
    ];
}

it('menambah murid dengan NIS otomatis berurutan per tahun masuk', function () {
    Murid::factory()->create(['nis' => 'TA20260041']);
    $terhapus = Murid::factory()->create(['nis' => 'TA20260042']);
    $terhapus->delete();
    Murid::factory()->create(['nis' => 'TA20250107']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/murid', dataMurid())
        ->assertCreated()
        ->assertJsonPath('data.nis', 'TA20260043')
        ->assertJsonPath('data.status', 'aktif')
        ->assertJsonPath('data.tanggal_lahir', '2022-03-14')
        ->assertJsonPath('data.kelas', null)
        ->assertJsonPath('data.wali.0.username', 'TA20260043')
        ->assertJsonPath('message', 'Aisyah Putri Ramadhani ditambahkan dengan NIS TA20260043. Akun wali murid memakai NIS ini sebagai username.');

    $this->postJson('/api/v1/murid', dataMurid(['tanggal_masuk' => '2027-01-04']))
        ->assertCreated()
        ->assertJsonPath('data.nis', 'TA20270001');
});

it('menyimpan foto murid di disk private dan mengembalikan signed URL', function () {
    Storage::fake('local');

    $response = $this->actingAs($this->kepsek)->post('/api/v1/murid', dataMurid([
        'foto' => UploadedFile::fake()->image('aisyah.png', 800, 1000),
    ]))->assertCreated();

    $murid = Murid::query()->findOrFail($response->json('data.id'));
    Storage::disk('local')->assertExists($murid->foto_path);
    expect($murid->foto_path)->toStartWith('murid/')
        ->and($response->json('data.foto_url'))->toContain('/api/v1/media/')->toContain('signature=');
});

it('memvalidasi isian murid', function (array $data, string $field) {
    Murid::factory()->create(['nisn' => '0221234567']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/murid', dataMurid($data))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'nama lengkap kosong' => [['nama_lengkap' => ''], 'nama_lengkap'],
    'jenis kelamin asing' => [['jenis_kelamin' => 'X'], 'jenis_kelamin'],
    'tanggal lahir di masa depan' => [['tanggal_lahir' => '2099-01-01'], 'tanggal_lahir'],
    'NISN sudah dipakai' => [['nisn' => '0221234567'], 'nisn'],
    'NIK bukan 16 digit' => [['nik' => '3374'], 'nik'],
    'status diisi saat menambah' => [['status' => 'lulus'], 'status'],
    'foto bukan gambar' => [['foto' => 'bukan-file'], 'foto'],
]);

it('mengubah status murid menjadi pindah dan menutup penempatan kelasnya', function () {
    $kelas = Kelas::factory()->for(TahunAjaran::factory()->aktif())->create();
    $murid = Murid::factory()->create();
    $kelas->murid()->attach($murid);

    $this->actingAs($this->kepsek)->putJson("/api/v1/murid/{$murid->id}", dataMurid([
        'status' => 'pindah',
        'tanggal_keluar' => '2026-09-15',
    ]))
        ->assertOk()
        ->assertJsonPath('data.status', 'pindah')
        ->assertJsonPath('data.tanggal_keluar', '2026-09-15')
        ->assertJsonPath('data.nis', $murid->nis);

    expect(KelasMurid::query()->where('murid_id', $murid->id)->value('status'))->toBe(StatusKelasMurid::Keluar)
        ->and($kelas->muridAktif()->count())->toBe(0);
});

it('membuka kembali penempatan kelas saat murid diaktifkan lagi', function () {
    $kelas = Kelas::factory()->for(TahunAjaran::factory()->aktif())->create();
    $murid = Murid::factory()->create(['status' => StatusMurid::Keluar, 'tanggal_keluar' => '2026-09-15']);
    $kelas->murid()->attach($murid, ['status' => StatusKelasMurid::Keluar]);

    $this->actingAs($this->kepsek)->putJson("/api/v1/murid/{$murid->id}", dataMurid(['status' => 'aktif']))
        ->assertOk()
        ->assertJsonPath('data.tanggal_keluar', null);

    expect($kelas->muridAktif()->count())->toBe(1);
});

it('menolak status dan tanggal keluar saat menambah murid', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/murid', dataMurid(['status' => 'lulus', 'tanggal_keluar' => '2027-06-25']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status', 'tanggal_keluar']);
});

it('mewajibkan status saat memperbarui murid', function () {
    $murid = Murid::factory()->create();

    $this->actingAs($this->kepsek)->putJson("/api/v1/murid/{$murid->id}", dataMurid())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('mewajibkan tanggal keluar untuk murid yang tidak aktif', function () {
    $murid = Murid::factory()->create();

    $this->actingAs($this->kepsek)->putJson("/api/v1/murid/{$murid->id}", dataMurid(['status' => 'keluar']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['tanggal_keluar']);
});

it('mengganti foto murid dan menghapus foto lama', function () {
    Storage::fake('local');
    Storage::disk('local')->put('murid/lama.jpg', 'foto lama');
    $murid = Murid::factory()->create(['foto_path' => 'murid/lama.jpg']);

    $this->actingAs($this->kepsek)->put("/api/v1/murid/{$murid->id}", dataMurid([
        'status' => 'aktif',
        'foto' => UploadedFile::fake()->image('baru.jpg'),
    ]))->assertOk();

    Storage::disk('local')->assertMissing('murid/lama.jpg');
    Storage::disk('local')->assertExists((string) $murid->fresh()?->foto_path);
});

it('menghapus murid yang salah input beserta penempatannya', function () {
    $kelas = Kelas::factory()->for(TahunAjaran::factory()->aktif())->create();
    $murid = Murid::factory()->create();
    $kelas->murid()->attach($murid);

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/murid/{$murid->id}")->assertOk();

    expect(Murid::query()->find($murid->id))->toBeNull()
        ->and($kelas->kelasMurid()->count())->toBe(0);
});

it('menolak menghapus murid yang sudah punya tagihan atau rapor', function (Closure $riwayat) {
    $murid = Murid::factory()->create();
    $riwayat($murid);

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/murid/{$murid->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(Murid::query()->find($murid->id))->not->toBeNull();
})->with([
    'tagihan' => [fn (Murid $murid) => Tagihan::factory()->for($murid)->create()],
    'rapor' => [fn (Murid $murid) => Rapor::factory()->for($murid)->create()],
]);

it('hanya Kepala Sekolah yang bisa menambah, mengubah, dan menghapus murid', function (Closure $akun) {
    $murid = Murid::factory()->create();
    $user = $akun();

    $this->actingAs($user)->postJson('/api/v1/murid', dataMurid())->assertForbidden();
    $this->actingAs($user)->putJson("/api/v1/murid/{$murid->id}", dataMurid(['status' => 'aktif']))->assertForbidden();
    $this->actingAs($user)->deleteJson("/api/v1/murid/{$murid->id}")->assertForbidden();
})->with([
    'guru' => [fn () => buatGuru()->user],
    'wali murid' => [fn () => User::factory()->waliMurid()->create()],
]);
