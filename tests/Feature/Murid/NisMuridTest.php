<?php

use App\Enums\Hubungan;
use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use App\Enums\StatusMurid;
use App\Models\Murid;
use App\Models\User;
use App\Models\WaliMurid;
use App\Notifications\UsernameWaliDiubahNotification;
use App\Services\NisMuridService;
use App\Services\WaliMuridService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    $this->kepsek = buatKepalaSekolah();
});

function muridMenungguNis(array $atribut = []): Murid
{
    return Murid::factory()->create(['nis' => null, ...$atribut]);
}

/**
 * @param  list<list<int|string|null>>  $baris
 * @param  list<string>  $judul
 */
function fileNis(array $baris, array $judul = NisMuridService::KOLOM): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([$judul, ...$baris]);
    $path = tempnam(sys_get_temp_dir(), 'nis-murid');
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'nis-murid.xlsx', null, null, true);
}

it('hanya Kepala Sekolah yang bisa mengisi NIS, termasuk bukan guru dengan izin kelola data murid', function () {
    $murid = muridMenungguNis();
    $guru = buatGuru();
    $guru->izin()->create(['modul' => ModulIzin::DataMurid, 'level' => LevelIzin::Kelola]);
    $this->actingAs($guru->user);

    $this->patchJson("/api/v1/murid/{$murid->id}/nis", ['nis' => 'TA001'])->assertForbidden();
    $this->postJson('/api/v1/murid/nis/batch', ['items' => [['murid_id' => $murid->id, 'nis' => 'TA001']]])->assertForbidden();
    $this->postJson('/api/v1/murid/nis/batch/pratinjau', ['items' => [['murid_id' => $murid->id, 'nis' => 'TA001']]])->assertForbidden();
    $this->post('/api/v1/murid/nis/import', ['file' => fileNis([[$murid->id, $murid->nama_lengkap, null, 'TA001']])])->assertForbidden();
    $this->get('/api/v1/murid/nis/template')->assertForbidden();

    expect($murid->fresh()?->nis)->toBeNull();
});

it('menyimpan NIS dalam huruf besar tanpa spasi, menerima garis miring, dan memakainya sebagai username', function () {
    $murid = muridMenungguNis(['nama_panggilan' => 'Kirana', 'tanggal_lahir' => '2022-03-09']);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$murid->id}/nis", ['nis' => ' 001/ ta8 '])
        ->assertOk()
        ->assertJsonPath('data.nis', '001/TA8')
        ->assertJsonPath('data.wali.0.username', '001/TA8');

    $this->postJson('/api/v1/auth/wali/login', ['username' => '001/ta8', 'password' => '09032022'])->assertOk();
    expect($this->actingAs($this->kepsek)->get("/api/v1/murid/{$murid->id}/kartu-akun")->headers->get('content-disposition'))
        ->toContain('kartu-akun-001-TA8.pdf');
});

it('menolak NIS yang formatnya salah, terlalu panjang, atau sudah dipakai', function (Closure $siapkan, string $nis, string $pesan) {
    $siapkan();
    $murid = muridMenungguNis();

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$murid->id}/nis", ['nis' => $nis])
        ->assertStatus(422)
        ->assertJsonPath('errors.nis', [$pesan]);

    expect($murid->fresh()?->nis)->toBeNull();
})->with([
    'karakter lain' => [fn () => null, 'TA#001', 'NIS hanya boleh berisi huruf, angka, titik, strip, dan garis miring.'],
    'lebih dari 20 karakter' => [fn () => null, str_repeat('1', 21), 'NIS maksimal 20 karakter.'],
    'dipakai murid lain' => [fn () => Murid::factory()->create(['nis' => 'TA001', 'nama_lengkap' => 'Bima Satria']), 'ta001', 'NIS TA001 sudah dipakai Bima Satria.'],
    'dipakai murid yang sudah dihapus' => [fn () => Murid::factory()->create(['nis' => 'TA001', 'nama_lengkap' => 'Bima Satria'])->delete(), 'TA001', 'NIS TA001 sudah dipakai Bima Satria.'],
    'dipakai username akun lain' => [fn () => User::factory()->waliMurid()->create(['username' => 'TA001']), 'TA001', 'NIS TA001 sudah dipakai sebagai username akun wali murid lain.'],
]);

it('mengubah NIS mengganti username akun otomatisnya dan memberi tahu walinya tanpa mencabut sesi', function () {
    $murid = muridMenungguNis(['nama_lengkap' => 'Raka Aditya Pratama', 'nama_panggilan' => 'Raka']);
    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$murid->id}/nis", ['nis' => 'TA001'])->assertOk();
    $akun = User::query()->where('username', 'TA001')->sole();
    $akun->createToken('web');

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$murid->id}/nis", ['nis' => 'TA002'])
        ->assertOk()
        ->assertJsonPath('message', 'NIS Raka Aditya Pratama diubah dari TA001 menjadi TA002.')
        ->assertJsonPath('data.wali.0.username', 'TA002');

    expect($akun->fresh()?->username)->toBe('TA002')
        ->and($akun->tokens()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'nis_diubah')->sole()->properties->all())->toBe(['dari' => 'TA001', 'ke' => 'TA002'])
        ->and(Activity::query()->where('event', 'username_diubah')->sole()->subject_id)->toBe($akun->id);
    Notification::assertSentTo($akun, UsernameWaliDiubahNotification::class, fn ($notifikasi) => $notifikasi->toDatabase($akun) === [
        'jenis' => 'username_diubah',
        'judul' => 'Username akun Anda berubah',
        'pesan' => 'NIS Raka diubah sekolah menjadi TA002. Mulai sekarang masuk dengan username TA002; password Anda tidak berubah.',
        'url' => '/dashboard/profil',
    ]);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$murid->id}/nis", ['nis' => 'TA002'])
        ->assertStatus(422)
        ->assertJsonPath('errors.nis', ['NIS ini sama dengan NIS sekarang.']);
});

it('tidak mengubah akun keluarga yang username-nya NIS kakak saat NIS adik diubah', function () {
    $kakak = Murid::factory()->create(['nis' => 'TA2025001']);
    $adik = Murid::factory()->create(['nis' => 'TA2026001']);
    $keluarga = WaliMurid::factory()->for(User::factory()->waliMurid()->state(['username' => 'TA2025001']))->create();
    $kakak->waliMurid()->attach($keluarga, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $adik->waliMurid()->attach($keluarga, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$adik->id}/nis", ['nis' => 'TA2026009'])->assertOk();

    expect($keluarga->user->fresh()?->username)->toBe('TA2025001');
    Notification::assertNothingSent();
});

it('mengisi NIS tanpa membuat akun untuk murid yang sudah punya wali atau tidak aktif', function () {
    $tertaut = muridMenungguNis();
    $tertaut->waliMurid()->attach(WaliMurid::factory()->create(), ['hubungan' => Hubungan::Ayah, 'is_kontak_utama' => true]);
    $alumni = muridMenungguNis(['status' => StatusMurid::Lulus, 'tahun_lulus' => 2025]);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$tertaut->id}/nis", ['nis' => 'TA001'])
        ->assertOk()
        ->assertJsonPath('message', "NIS TA001 tersimpan untuk {$tertaut->nama_lengkap}.");
    $this->patchJson("/api/v1/murid/{$alumni->id}/nis", ['nis' => 'TA002'])->assertOk();

    expect(User::query()->whereIn('username', ['TA001', 'TA002'])->exists())->toBeFalse()
        ->and($alumni->fresh()?->nis)->toBe('TA002');
});

it('pratinjau tabel menandai semua baris dobel dan menghitung akun wali yang akan dibuat', function () {
    $raka = muridMenungguNis();
    $salma = muridMenungguNis();
    $tertaut = muridMenungguNis();
    $tertaut->waliMurid()->attach(WaliMurid::factory()->create(), ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $sudahAda = Murid::factory()->create(['nis' => 'TA900', 'nama_lengkap' => 'Dimas Prasetyo']);
    $kosong = muridMenungguNis();

    $respons = $this->actingAs($this->kepsek)->postJson('/api/v1/murid/nis/batch/pratinjau', ['items' => [
        ['murid_id' => $raka->id, 'nis' => 'ta 001'],
        ['murid_id' => $salma->id, 'nis' => 'TA001'],
        ['murid_id' => $tertaut->id, 'nis' => 'TA002'],
        ['murid_id' => $sudahAda->id, 'nis' => 'TA003'],
        ['murid_id' => 999, 'nis' => 'TA004'],
        ['murid_id' => $kosong->id, 'nis' => null],
    ]])->assertOk();

    expect($respons->json('data.ringkasan'))->toBe(['total' => 6, 'valid' => 1, 'invalid' => 5, 'akun_wali' => 0])
        ->and($respons->json('data.baris.*.alasan'))->toBe([
            ['NIS TA001 dobel dengan baris 2.'],
            ['NIS TA001 dobel dengan baris 1.'],
            [],
            ['Dimas Prasetyo sudah punya NIS TA900.'],
            ['Murid dengan ID 999 tidak ditemukan.'],
            ['NIS wajib diisi.'],
        ])
        ->and($respons->json('data.baris.0.nis'))->toBe('TA001')
        ->and($respons->json('data.baris.2.akun_wali'))->toBeFalse()
        ->and(Murid::query()->whereNotNull('nis')->count())->toBe(1);

    $this->postJson('/api/v1/murid/nis/batch/pratinjau', ['items' => [['murid_id' => $raka->id, 'nis' => 'TA010']]])
        ->assertJsonPath('data.ringkasan.akun_wali', 1);
});

it('menyimpan baris valid dari tabel, membuat akun walinya, dan mengembalikan baris gagal', function () {
    $raka = muridMenungguNis(['tanggal_lahir' => '2021-05-14']);
    $salma = muridMenungguNis();

    $this->actingAs($this->kepsek)->postJson('/api/v1/murid/nis/batch', ['items' => [
        ['murid_id' => $raka->id, 'nis' => 'TA001'],
        ['murid_id' => $salma->id, 'nis' => 'TA#2'],
    ]])
        ->assertOk()
        ->assertJsonPath('message', '1 NIS tersimpan, 1 akun wali dibuat, 1 baris gagal.')
        ->assertJsonPath('data.diisi', 1)
        ->assertJsonPath('data.akun_wali_dibuat', 1)
        ->assertJsonPath('data.gagal.0.murid_id', $salma->id)
        ->assertJsonPath('data.gagal.0.alasan', ['NIS hanya boleh berisi huruf, angka, titik, strip, dan garis miring.']);

    $akun = User::query()->where('username', 'TA001')->sole();
    expect($raka->fresh()?->nis)->toBe('TA001')
        ->and($salma->fresh()?->nis)->toBeNull()
        ->and(Hash::check('14052021', (string) $akun->password))->toBeTrue();
});

it('tidak menyimpan NIS apa pun kalau ada bentrok unik saat menyimpan', function () {
    $tertaut = muridMenungguNis();
    $tertaut->waliMurid()->attach(WaliMurid::factory()->create(), ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $raka = muridMenungguNis();
    $this->mock(WaliMuridService::class)->shouldReceive('buatAkunOtomatis')
        ->andThrow(new UniqueConstraintViolationException('sqlite', 'insert into users', [], new PDOException('UNIQUE constraint failed: users.username')));

    $this->actingAs($this->kepsek)->postJson('/api/v1/murid/nis/batch', ['items' => [
        ['murid_id' => $tertaut->id, 'nis' => 'TA001'],
        ['murid_id' => $raka->id, 'nis' => 'TA002'],
    ]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(Murid::query()->whereNotNull('nis')->exists())->toBeFalse();
});

it('menyediakan daftar murid aktif yang menunggu NIS dengan kolom NIS berformat teks', function () {
    $salma = muridMenungguNis(['nama_lengkap' => 'Salma Azzahra', 'tanggal_lahir' => '2021-08-17']);
    $raka = muridMenungguNis(['nama_lengkap' => 'Raka Aditya Pratama', 'tanggal_lahir' => '2021-05-14']);
    muridMenungguNis(['status' => StatusMurid::Lulus, 'tahun_lulus' => 2025]);
    Murid::factory()->create(['nis' => 'TA900']);

    $respons = $this->actingAs($this->kepsek)->get('/api/v1/murid/nis/template')->assertOk();
    $sheet = IOFactory::load($respons->getFile()->getPathname())->getSheet(0);

    expect($respons->headers->get('content-disposition'))->toContain('murid-menunggu-nis.xlsx')
        ->and($sheet->getTitle())->toBe('Murid')
        ->and($sheet->getHighestDataRow('A'))->toBe(3)
        ->and($sheet->rangeToArray('A1:D3', null, true, false))->toBe([
            ['id', 'nama_lengkap', 'tanggal_lahir', 'nis'],
            [$raka->id, 'Raka Aditya Pratama', '14 Mei 2021', null],
            [$salma->id, 'Salma Azzahra', '17 Agustus 2021', null],
        ])
        ->and($sheet->getStyle('D2')->getNumberFormat()->getFormatCode())->toBe('@');
});

it('memeriksa file NIS: nama dibandingkan setelah dirapikan, NIS teks tetap dengan nol di depan', function () {
    $raka = muridMenungguNis(['nama_lengkap' => 'Raka Aditya Pratama']);
    $salma = muridMenungguNis(['nama_lengkap' => 'Salma Azzahra']);
    $dimas = muridMenungguNis();

    $respons = $this->actingAs($this->kepsek)->post('/api/v1/murid/nis/import/pratinjau', ['file' => fileNis([
        [$raka->id, '  raka   aditya PRATAMA ', '14 Mei 2021', '0012'],
        [$salma->id, 'Salsa Azzahra', null, '0013'],
        ['abc', 'Tanpa ID', null, '0014'],
        [$dimas->id, $dimas->nama_lengkap, null, null],
    ])])->assertOk();

    expect($respons->json('data.baris.*.nomor'))->toBe([2, 3, 4, 5])
        ->and($respons->json('data.baris.0.valid'))->toBeTrue()
        ->and($respons->json('data.baris.0.nis'))->toBe('0012')
        ->and($respons->json('data.baris.1.alasan'))->toBe(["Nama Salsa Azzahra tidak sama dengan murid ID {$salma->id} (Salma Azzahra). Periksa apakah kolom id tergeser."])
        ->and($respons->json('data.baris.2.alasan'))->toBe(['ID murid kosong atau bukan angka. Jangan ubah kolom id dari file yang diunduh.'])
        ->and($respons->json('data.baris.3.alasan'))->toBe(['NIS wajib diisi.'])
        ->and(Murid::query()->whereNotNull('nis')->exists())->toBeFalse();
});

it('mengisi NIS dari file lebih dari satu kelompok akun dan mengembalikan batas waktu PHP seperti semula', function () {
    $baris = Murid::factory()->count(60)->create(['nis' => null])
        ->map(fn (Murid $murid, int $i): array => [$murid->id, $murid->nama_lengkap, null, sprintf('TA%03d', $i + 1)])->all();

    $this->actingAs($this->kepsek)->post('/api/v1/murid/nis/import', ['file' => fileNis($baris)])
        ->assertOk()
        ->assertJsonPath('data.diisi', 60)
        ->assertJsonPath('data.akun_wali_dibuat', 60)
        ->assertJsonPath('data.gagal', []);

    expect(ini_get('max_execution_time'))->toBe('0')
        ->and(User::query()->where('role', 'wali_murid')->count())->toBe(60);
});

it('menolak file NIS yang bukan xlsx, tanpa kolom wajib, atau tanpa data', function (Closure $file, string $pesan) {
    $this->actingAs($this->kepsek)->post('/api/v1/murid/nis/import/pratinjau', ['file' => $file()])
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', $pesan);
})->with([
    'CSV' => [fn () => UploadedFile::fake()->createWithContent('nis.csv', "id,nama_lengkap,nis\n1,Raka,TA001\n"), 'File harus berformat .xlsx.'],
    'kolom nis hilang' => [fn () => fileNis([[1, 'Raka']], ['id', 'nama_lengkap']), 'Kolom nis tidak ada di file. Pakai file dari tombol Unduh Daftar Murid.'],
    'hanya judul kolom' => [fn () => fileNis([]), 'File tidak berisi data murid. Isi NIS mulai baris kedua, di bawah judul kolom.'],
]);

it('menyaring daftar murid yang menunggu NIS dan menghitungnya di beranda Kepala Sekolah', function () {
    $menunggu = muridMenungguNis();
    muridMenungguNis(['status' => StatusMurid::Pindah]);
    Murid::factory()->create(['nis' => 'TA900']);

    $this->actingAs($this->kepsek)->getJson('/api/v1/murid?filter[menunggu_nis]=true&filter[status]=aktif')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $menunggu->id)
        ->assertJsonPath('data.0.nis', null);
    $this->getJson('/api/v1/murid?filter[menunggu_nis]=false')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/dashboard')->assertJsonPath('data.tertunda.murid_menunggu_nis', 1);
});
