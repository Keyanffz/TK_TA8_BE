<?php

use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Enums\StatusPembayaran;
use App\Enums\StatusPendaftaran;
use App\Enums\StatusRapor;
use App\Enums\StatusTagihan;
use App\Models\ElemenPenilaian;
use App\Models\GaleriAlbum;
use App\Models\Guru;
use App\Models\KegiatanFoto;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pembayaran;
use App\Models\Pendaftaran;
use App\Models\Pengaturan;
use App\Models\Rapor;
use App\Models\Tagihan;
use App\Models\User;
use App\Models\WaliMurid;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'superadmin.name' => 'Hj. Umi Kulsum, S.Pd.',
        'superadmin.email' => 'kepsek@tkta8.test',
        'superadmin.password' => 'kepsek2026',
    ]);
});

it('membuat satu Kepala Sekolah beserta profil gurunya, elemen penilaian, dan pengaturan awal', function () {
    $this->seed(DatabaseSeeder::class);

    $kepsek = User::query()->where('role', Role::SuperAdmin)->sole();

    expect($kepsek->status)->toBe(StatusAkun::Aktif)
        ->and(Hash::check('kepsek2026', (string) $kepsek->password))->toBeTrue()
        ->and($kepsek->guru?->jabatan)->toBe(Guru::JABATAN_KEPALA_SEKOLAH)
        ->and(ElemenPenilaian::query()->orderBy('urutan')->pluck('kode')->all())->toBe(['NAB', 'JD', 'LITERASI_STEAM'])
        ->and(Pengaturan::query()->count())->toBe(25)
        ->and(Pengaturan::query()->where('kunci', 'keuangan.tanggal_jatuh_tempo')->value('nilai'))->toBe(10)
        ->and(Pengaturan::query()->where('kunci', 'ppdb.dibuka')->value('nilai'))->toBeFalse();
});

it('tidak mengubah akun dan pengaturan yang sudah ada saat seeder dijalankan ulang', function () {
    $this->seed(DatabaseSeeder::class);
    Pengaturan::query()->where('kunci', 'profil.visi')->firstOrFail()->update(['nilai' => 'Visi baru dari Kepala Sekolah.']);
    config(['superadmin.password' => 'passwordLain99']);

    $this->seed(DatabaseSeeder::class);

    $kepsek = User::query()->where('role', Role::SuperAdmin)->sole();
    expect(Hash::check('kepsek2026', (string) $kepsek->password))->toBeTrue()
        ->and(Guru::query()->where('user_id', $kepsek->id)->count())->toBe(1)
        ->and(Pengaturan::query()->where('kunci', 'profil.visi')->value('nilai'))->toBe('Visi baru dari Kepala Sekolah.');
});

it('menolak membuat Kepala Sekolah tanpa SUPERADMIN_* yang valid', function () {
    config(['superadmin.password' => 'pendek']);

    expect(fn () => $this->seed(DatabaseSeeder::class))->toThrow(RuntimeException::class, 'SUPERADMIN_PASSWORD');
    expect(User::query()->count())->toBe(0);
});

it('menolak Kepala Sekolah kedua dengan email berbeda', function () {
    $this->seed(DatabaseSeeder::class);
    config(['superadmin.email' => 'kepsek.baru@tkta8.test']);

    expect(fn () => $this->seed(DatabaseSeeder::class))->toThrow(RuntimeException::class, 'Hanya boleh ada satu');
});

it('mengisi data demo sesuai B8', function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->seed(DemoSeeder::class);

    $kelasAktif = Kelas::query()->whereHas('tahunAjaran', fn ($ta) => $ta->where('is_aktif', true));
    $guruAktif = Guru::query()->whereHas('user', fn ($user) => $user->where('role', Role::Guru)->where('status', StatusAkun::Aktif));

    expect($kelasAktif->pluck('nama')->sort()->values()->all())->toBe(['TK A1', 'TK A2', 'TK B1', 'TK B2'])
        ->and($guruAktif->count())->toBe(6)
        ->and((clone $guruAktif)->where('bisa_kelola_keuangan', true)->count())->toBe(1)
        ->and(User::query()->where('status', StatusAkun::Pending)->count())->toBe(2)
        ->and(Murid::query()->has('kelas')->count())->toBe(60)
        ->and(WaliMurid::query()->count())->toBeGreaterThanOrEqual(40)
        ->and(WaliMurid::query()->has('murid', '>=', 2)->exists())->toBeTrue()
        ->and(Murid::query()->has('waliMurid', '>=', 2)->exists())->toBeTrue()
        ->and(Murid::query()->whereNotNull('kode_tautan')->doesntHave('waliMurid')->exists())->toBeTrue();

    expect(Tagihan::query()->whereNotNull('periode')->distinct()->pluck('periode')->map->toDateString()->sort()->values()->all())
        ->toBe(['2026-07-01', '2026-08-01', '2026-09-01'])
        ->and(Tagihan::query()->pluck('status')->map->value->unique()->sort()->values()->all())
        ->toBe([StatusTagihan::Lunas->value, StatusTagihan::MenungguVerifikasi->value, StatusTagihan::Terlambat->value])
        ->and(Pembayaran::query()->where('status', StatusPembayaran::Menunggu)->count())->toBeGreaterThan(0)
        ->and(Rapor::query()->pluck('status')->map->value->unique()->sort()->values()->all())
        ->toBe([StatusRapor::Diajukan->value, StatusRapor::Draft->value, StatusRapor::Revisi->value, StatusRapor::Terbit->value])
        ->and(Pendaftaran::query()->count())->toBe(5)
        ->and(Pendaftaran::query()->where('status', StatusPendaftaran::Diterima)->sole()->murid?->waliMurid)->toHaveCount(1)
        ->and(GaleriAlbum::query()->where('is_publik', true)->count())->toBe(2)
        ->and(Pengaturan::query()->where('kunci', 'ppdb.dibuka')->value('nilai'))->toBeTrue()
        ->and(Pengaturan::query()->where('kunci', 'beranda.info_wali')->value('nilai')['aktif'])->toBeTrue()
        ->and(User::query()->pluck('email')->reject(fn (string $email) => str_ends_with($email, '.test'))->all())->toBe([]);

    Storage::disk('local')->assertExists(KegiatanFoto::query()->value('path'));
    Storage::disk('public')->assertExists(GaleriAlbum::query()->value('cover_path'));
});

it('menolak menjalankan data demo di production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(DemoSeeder::class)->run())->toThrow(RuntimeException::class, 'production');
});
