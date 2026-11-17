<?php

use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Enums\StatusPendaftaran;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pendaftaran;
use App\Models\Pengaturan;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaliMurid;
use App\Notifications\PendaftaranBaruNotification;
use App\Notifications\PendaftaranDiprosesNotification;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * PPDB tahun ajaran 2027/2028 (mulai 12 Juli 2027) dibuka 1 September 2026 – 31 Maret 2027, kuota 2.
 * Hari ini 5 Oktober 2026. Ibu Sari wali murid yang akan mendaftar.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    Storage::fake('local');
    Notification::fake();
    $this->seed(PengaturanSeeder::class);

    $this->kepsek = buatKepalaSekolah();
    TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->tujuan = TahunAjaran::factory()->create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-23']);
    aturPpdb(['ppdb.dibuka' => true, 'ppdb.tanggal_buka' => '2026-09-01', 'ppdb.tanggal_tutup' => '2027-03-31', 'ppdb.tahun_ajaran_id' => $this->tujuan->id, 'ppdb.kuota' => 2]);

    $this->ibuSari = WaliMurid::factory()->create();
});

function aturPpdb(array $nilai): void
{
    foreach ($nilai as $kunci => $isi) {
        Pengaturan::query()->where('kunci', $kunci)->firstOrFail()->update(['nilai' => $isi]);
    }
}

function dataPendaftaran(array $timpa = []): array
{
    return [
        'hubungan' => 'ibu',
        'tingkat_tujuan' => 'A',
        'nama_lengkap' => 'Nadia Putri Rahma',
        'nama_panggilan' => 'Nadia',
        'jenis_kelamin' => 'P',
        'tempat_lahir' => 'Semarang',
        'tanggal_lahir' => '2023-02-14',
        'nik' => '3374015402230001',
        'agama' => 'Islam',
        'alamat' => 'Jl. Majapahit No. 12, RT 03/RW 05',
        'desa_kelurahan' => 'Pedurungan Kidul',
        'kecamatan' => 'Pedurungan',
        'kabupaten_kota' => 'Kota Semarang',
        'provinsi' => 'Jawa Tengah',
        'kewarganegaraan' => 'wni',
        'anak_ke' => 1,
        'jumlah_saudara_kandung' => 0,
        'status_yatim_piatu' => 'tidak',
        'golongan_darah' => 'O',
        'tinggi_badan' => 98.5,
        'berat_badan' => 15,
        'imunisasi' => ['bcg', 'polio'],
        'kontak_utama' => 'ibu',
        'ayah' => orangTuaPendaftaran(['nama' => 'Rahmat Hidayat', 'pekerjaan' => 'wiraswasta', 'no_hp' => '081234567891']),
        'ibu' => orangTuaPendaftaran(['nama' => 'Sari Wulandari', 'pekerjaan' => 'lainnya', 'pekerjaan_lainnya' => 'Guru les', 'no_hp' => '081234567890']),
        'pernyataan' => true,
        ...$timpa,
    ];
}

function orangTuaPendaftaran(array $timpa = []): array
{
    return [
        'status' => 'kandung',
        'nama' => 'Rahmat Hidayat',
        'tempat_lahir' => 'Semarang',
        'tanggal_lahir' => '1990-03-21',
        'agama' => 'Islam',
        'kewarganegaraan' => 'wni',
        'pendidikan_terakhir' => 'd4_s1',
        'pekerjaan' => 'wiraswasta',
        'penghasilan' => '2jt_5jt',
        'alamat_sama_dengan_anak' => true,
        'no_hp' => '081234567891',
        ...$timpa,
    ];
}

function daftarPpdb(object $test, WaliMurid $wali, array $timpa = []): Pendaftaran
{
    $id = $test->actingAs($wali->user)->postJson('/api/v1/pendaftaran', dataPendaftaran($timpa))->assertCreated()->json('data.id');

    return Pendaftaran::query()->findOrFail($id);
}

it('menampilkan status PPDB di landing page', function () {
    Pendaftaran::factory()->for($this->tujuan)->create();

    $this->getJson('/api/v1/public/ppdb')
        ->assertOk()
        ->assertJsonPath('data', [
            'dibuka' => true,
            'tanggal_buka' => '2026-09-01',
            'tanggal_tutup' => '2027-03-31',
            'kuota' => 2,
            'sisa_kuota' => 1,
            'info' => '',
            'tahun_ajaran' => ['id' => $this->tujuan->id, 'nama' => '2027/2028'],
        ]);
});

it('menganggap PPDB tutup di luar tanggal buka-tutup walau dibuka di pengaturan', function () {
    Carbon::setTestNow('2027-04-01 08:00:00');

    $this->getJson('/api/v1/public/ppdb')->assertJsonPath('data.dibuka', false);
    $this->actingAs($this->ibuSari->user)->postJson('/api/v1/pendaftaran', dataPendaftaran())
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'PPDB sedang ditutup. Lihat jadwal pendaftaran di halaman PPDB.');
});

it('menerima pendaftaran dari wali tanpa berkas dan memberi tahu Kepala Sekolah', function () {
    $response = $this->actingAs($this->ibuSari->user)->postJson('/api/v1/pendaftaran', dataPendaftaran())
        ->assertCreated()
        ->assertJsonPath('message', 'Pendaftaran Nadia terkirim dengan kode PPDB-2027-0001. Pantau statusnya di menu PPDB.')
        ->assertJsonPath('data.status', 'diajukan')
        ->assertJsonPath('data.tahun_ajaran.nama', '2027/2028')
        ->assertJsonPath('data.hubungan', 'ibu')
        ->assertJsonMissingPath('data.dokumen');

    $pendaftaran = Pendaftaran::query()->findOrFail($response->json('data.id'));
    expect(Schema::hasTable('pendaftaran_dokumen'))->toBeFalse();

    Notification::assertSentTo($this->kepsek, PendaftaranBaruNotification::class, fn ($notifikasi) => $notifikasi->toDatabase($this->kepsek) === [
        'jenis' => 'pendaftaran_baru',
        'judul' => 'Pendaftar PPDB baru',
        'pesan' => 'Nadia Putri Rahma didaftarkan ke Kelompok A (PPDB-2027-0001). Berkasnya dibawa pendaftar ke sekolah.',
        'url' => "/mudarris/ppdb/{$pendaftaran->id}",
    ]);
});

it('menolak pendaftaran saat kuota penuh, tanpa menghitung pendaftaran yang ditolak', function () {
    Pendaftaran::factory()->for($this->tujuan)->create();
    Pendaftaran::factory()->for($this->tujuan)->create(['status' => StatusPendaftaran::Ditolak]);
    daftarPpdb($this, $this->ibuSari);

    $this->actingAs(WaliMurid::factory()->create()->user)->postJson('/api/v1/pendaftaran', dataPendaftaran(['nik' => '3374015402230002']))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Kuota PPDB tahun ajaran 2027/2028 sudah penuh.');

    $this->getJson('/api/v1/public/ppdb')->assertJsonPath('data.sisa_kuota', 0);
});

it('menolak anak dengan NIK yang punya pendaftaran selain ditolak, di tahun ajaran mana pun', function (StatusPendaftaran $status) {
    Pendaftaran::factory()->create(['nik' => '3374015402230001', 'status' => $status]);

    $this->actingAs($this->ibuSari->user)->postJson('/api/v1/pendaftaran', dataPendaftaran())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Anak dengan NIK ini sudah punya pendaftaran PPDB yang sedang diproses atau sudah diterima.');
})->with([StatusPendaftaran::Diajukan, StatusPendaftaran::Diverifikasi, StatusPendaftaran::Diterima]);

it('menolak anak yang NIK-nya sudah terdaftar sebagai murid', function () {
    Murid::factory()->create(['nik' => '3374015402230001']);

    $this->actingAs($this->ibuSari->user)->postJson('/api/v1/pendaftaran', dataPendaftaran())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Anak dengan NIK ini sudah terdaftar sebagai murid. Wali yang sudah punya akun bisa menambahkannya lewat menu Tambah Anak; kalau belum, minta kartu akun ke sekolah.');
});

it('membolehkan pendaftar yang pernah ditolak mendaftar ulang', function () {
    Pendaftaran::factory()->for($this->tujuan)->create(['nik' => '3374015402230001', 'status' => StatusPendaftaran::Ditolak]);

    $this->actingAs($this->ibuSari->user)->postJson('/api/v1/pendaftaran', dataPendaftaran())->assertCreated();
});

it('memvalidasi data pendaftaran', function (array $timpa, string $field) {
    $this->actingAs($this->ibuSari->user)->postJson('/api/v1/pendaftaran', dataPendaftaran($timpa))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'NIK 15 digit' => [['nik' => '337401540223000'], 'nik'],
    'hubungan tidak dikenal' => [['hubungan' => 'kakek'], 'hubungan'],
    'nomor HP salah' => [['ibu' => orangTuaPendaftaran(['no_hp' => '6281234'])], 'ibu.no_hp'],
    'agama di luar daftar' => [['agama' => 'Kepercayaan lain'], 'agama'],
]);

it('menjalankan alur verifikasi lalu terima: membuat murid tanpa foto, menautkan wali, dan menempatkan di kelas', function () {
    $pendaftaran = daftarPpdb($this, $this->ibuSari);
    $kelas = Kelas::factory()->for($this->tujuan)->create(['nama' => 'TK A1']);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/verifikasi")
        ->assertOk()
        ->assertJsonPath('data.status', 'diverifikasi');
    Notification::assertSentTo($this->ibuSari->user, PendaftaranDiprosesNotification::class, fn ($notifikasi) => $notifikasi->toDatabase($this->ibuSari->user)['pesan']
        === 'Data pendaftaran Nadia (PPDB-2027-0001) sudah dicek sekolah. Tunggu keputusan akhir dari sekolah.');

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/terima", ['kelas_id' => $kelas->id])
        ->assertOk()
        ->assertJsonPath('message', 'Nadia Putri Rahma diterima sebagai murid.')
        ->assertJsonPath('data.status', 'diterima')
        ->assertJsonPath('data.murid.nis', null);

    $murid = Murid::query()->with(['waliMurid', 'kelas'])->where('nama_lengkap', 'Nadia Putri Rahma')->sole();
    expect($murid->nis)->toBeNull()
        ->and($murid->status)->toBe(StatusMurid::Aktif)
        ->and($murid->tanggal_masuk->toDateString())->toBe('2027-07-12')
        ->and($murid->nik)->toBe('3374015402230001')
        ->and($murid->foto_path)->toBeNull()
        ->and($murid->waliMurid->sole()->id)->toBe($this->ibuSari->id)
        ->and($murid->waliMurid->sole()->pivot->hubungan->value)->toBe('ibu')
        ->and($murid->waliMurid->sole()->pivot->is_kontak_utama)->toBeTrue()
        ->and($murid->kelas->sole()->id)->toBe($kelas->id)
        ->and($murid->kelas->sole()->pivot->status)->toBe(StatusKelasMurid::Aktif)
        ->and(User::query()->where('role', 'wali_murid')->count())->toBe(1);
    expect(Storage::disk('local')->allFiles())->toBe([]);

    Notification::assertSentTo($this->ibuSari->user, PendaftaranDiprosesNotification::class, fn ($notifikasi) => $notifikasi->toDatabase($this->ibuSari->user)['pesan']
        === 'Nadia (PPDB-2027-0001) diterima di TK A1 dan sudah tertaut ke akun Anda.');
    expect(Activity::query()->where('log_name', 'ppdb')->pluck('event')->all())->toBe(['diverifikasi', 'diterima']);
});

it('menerima tanpa kelas dan menolak kelas dari tahun ajaran lain', function () {
    $pendaftaran = daftarPpdb($this, $this->ibuSari);
    $pendaftaran->update(['status' => StatusPendaftaran::Diverifikasi]);
    $kelasLama = Kelas::factory()->for(TahunAjaran::query()->where('nama', '2026/2027')->sole())->create(['nama' => 'TK A2']);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/terima", ['kelas_id' => $kelasLama->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Kelas TK A2 bukan kelas tahun ajaran tujuan pendaftaran ini.');
    expect(Murid::query()->count())->toBe(0);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/terima")->assertOk();
    Notification::assertSentTo($this->ibuSari->user, PendaftaranDiprosesNotification::class, fn ($notifikasi) => str_contains($notifikasi->toDatabase($this->ibuSari->user)['pesan'], 'Kelasnya akan diinformasikan sekolah.'));
});

it('membatalkan penerimaan seluruhnya kalau kelas tujuan penuh', function () {
    $pendaftaran = daftarPpdb($this, $this->ibuSari);
    $pendaftaran->update(['status' => StatusPendaftaran::Diverifikasi]);
    $kelas = Kelas::factory()->for($this->tujuan)->create(['kapasitas' => 1]);
    $kelas->murid()->attach(Murid::factory()->create());

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/terima", ['kelas_id' => $kelas->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(Murid::query()->count())->toBe(1)
        ->and($pendaftaran->fresh()?->status)->toBe(StatusPendaftaran::Diverifikasi);
});

it('menolak pendaftaran dengan alasan dan memberi tahu wali', function () {
    $pendaftaran = daftarPpdb($this, $this->ibuSari);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/tolak", ['alasan' => 'Usia anak belum 4 tahun pada 1 Juli 2027.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'ditolak')
        ->assertJsonPath('data.catatan', 'Usia anak belum 4 tahun pada 1 Juli 2027.');

    Notification::assertSentTo($this->ibuSari->user, PendaftaranDiprosesNotification::class, fn ($notifikasi) => $notifikasi->toDatabase($this->ibuSari->user)['pesan']
        === 'Pendaftaran Nadia (PPDB-2027-0001) ditolak: Usia anak belum 4 tahun pada 1 Juli 2027.');
});

it('hanya mengizinkan transisi status sesuai alur PPDB', function (StatusPendaftaran $status, string $aksi) {
    $pendaftaran = Pendaftaran::factory()->for($this->tujuan)->create(['status' => $status]);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/{$aksi}", ['alasan' => 'Tidak lengkap.'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
})->with([
    'terima yang belum diverifikasi' => [StatusPendaftaran::Diajukan, 'terima'],
    'verifikasi ulang' => [StatusPendaftaran::Diverifikasi, 'verifikasi'],
    'tolak yang sudah diterima' => [StatusPendaftaran::Diterima, 'tolak'],
    'terima yang ditolak' => [StatusPendaftaran::Ditolak, 'terima'],
]);

it('menampilkan pendaftaran milik wali sendiri dan semua pendaftaran ke Kepala Sekolah', function () {
    $milikSari = daftarPpdb($this, $this->ibuSari);
    $lain = Pendaftaran::factory()->for($this->tujuan)->create();

    $this->actingAs($this->ibuSari->user)->getJson('/api/v1/pendaftaran')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $milikSari->id);
    $this->actingAs($this->ibuSari->user)->getJson("/api/v1/pendaftaran/{$lain->id}")->assertNotFound();
    $this->actingAs($this->ibuSari->user)->getJson("/api/v1/pendaftaran/{$milikSari->id}")->assertOk()->assertJsonMissingPath('data.dokumen');

    $this->actingAs($this->kepsek)->getJson('/api/v1/pendaftaran?filter[status]=diajukan')->assertJsonPath('meta.total', 2);
    $this->actingAs($this->kepsek)->getJson('/api/v1/pendaftaran?search=Nadia')->assertJsonPath('meta.total', 1);
});

it('menutup PPDB untuk guru dan menolak wali memproses pendaftaran', function () {
    $pendaftaran = Pendaftaran::factory()->for($this->tujuan)->create();
    $guru = buatGuru()->user;

    $this->actingAs($guru)->getJson('/api/v1/pendaftaran')->assertForbidden();
    $this->actingAs($guru)->postJson('/api/v1/pendaftaran', dataPendaftaran())->assertForbidden();
    $this->actingAs($this->ibuSari->user)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/verifikasi")->assertForbidden();
    $this->actingAs(User::factory()->superAdmin()->create())->postJson('/api/v1/pendaftaran', dataPendaftaran())->assertForbidden();
});

function daftarPpdbPublik(object $test, array $timpa = []): Pendaftaran
{
    $kode = $test->postJson('/api/v1/public/pendaftaran', dataPendaftaran($timpa))->assertCreated()->json('data.kode');

    return Pendaftaran::query()->where('kode', $kode)->sole();
}

it('menerima pendaftaran tanpa login dan membalas kode pendaftaran', function () {
    $this->postJson('/api/v1/public/pendaftaran', dataPendaftaran())
        ->assertCreated()
        ->assertJsonPath('message', 'Pendaftaran Nadia terkirim dengan kode PPDB-2027-0001. Simpan kode ini untuk mengecek status pendaftaran.')
        ->assertJsonPath('data', [
            'kode' => 'PPDB-2027-0001',
            'status' => 'diajukan',
            'nama_panggilan' => 'Nadia',
            'tingkat_tujuan' => 'A',
            'tahun_ajaran' => ['id' => $this->tujuan->id, 'nama' => '2027/2028'],
            'catatan' => null,
            'diproses_at' => null,
            'created_at' => '2026-10-05T10:00:00+07:00',
        ]);

    $pendaftaran = Pendaftaran::query()->sole();
    expect($pendaftaran->wali_murid_id)->toBeNull()
        ->and($pendaftaran->hubungan)->toBeNull()
        ->and($pendaftaran->kontak_utama->value)->toBe('ibu');
    Notification::assertSentTo($this->kepsek, PendaftaranBaruNotification::class);

    $this->actingAs($this->kepsek)->getJson("/api/v1/pendaftaran/{$pendaftaran->id}")
        ->assertOk()
        ->assertJsonPath('data.wali', null);
});

it('menerapkan aturan jadwal, kuota, dan NIK dobel pada pendaftaran tanpa login', function () {
    daftarPpdbPublik($this);

    $this->postJson('/api/v1/public/pendaftaran', dataPendaftaran())
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Anak dengan NIK ini sudah punya pendaftaran PPDB yang sedang diproses atau sudah diterima.');

    aturPpdb(['ppdb.dibuka' => false]);
    $this->postJson('/api/v1/public/pendaftaran', dataPendaftaran(['nik' => '3374015402230002']))
        ->assertStatus(422)
        ->assertJsonPath('message', 'PPDB sedang ditutup. Lihat jadwal pendaftaran di halaman PPDB.');
});

it('membatasi pendaftaran tanpa login 3 kali per jam per IP', function () {
    aturPpdb(['ppdb.kuota' => 10]);

    foreach (['3374015402230001', '3374015402230002', '3374015402230003'] as $nik) {
        $this->postJson('/api/v1/public/pendaftaran', dataPendaftaran(['nik' => $nik]))->assertCreated();
    }

    $this->postJson('/api/v1/public/pendaftaran', dataPendaftaran(['nik' => '3374015402230004']))
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/api/v1/public/pendaftaran', dataPendaftaran(['nik' => '3374015402230004']))
        ->assertCreated();
});

it('menampilkan status pendaftaran dari kode dan tanggal lahir anak tanpa login', function () {
    $pendaftaran = daftarPpdbPublik($this);
    $pendaftaran->update(['status' => StatusPendaftaran::Ditolak, 'catatan' => 'Usia anak belum 4 tahun pada 1 Juli 2027.', 'diproses_at' => now()]);

    $this->getJson('/api/v1/public/pendaftaran/status?kode=ppdb-2027-0001&tanggal_lahir=2023-02-14')
        ->assertOk()
        ->assertJsonPath('data.kode', 'PPDB-2027-0001')
        ->assertJsonPath('data.status', 'ditolak')
        ->assertJsonPath('data.catatan', 'Usia anak belum 4 tahun pada 1 Juli 2027.')
        ->assertJsonMissingPath('data.nik')
        ->assertJsonMissingPath('data.dokumen');

    $this->getJson('/api/v1/public/pendaftaran/status?kode=PPDB-2027-0001&tanggal_lahir=2023-02-15')
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND');
    $this->getJson('/api/v1/public/pendaftaran/status?kode=PPDB-2027-0001')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['tanggal_lahir']);
});

it('membatasi cek status pendaftaran 10 kali per menit per IP', function () {
    foreach (range(1, 10) as $_) {
        $this->getJson('/api/v1/public/pendaftaran/status?kode=PPDB-2027-0009&tanggal_lahir=2023-02-14')->assertNotFound();
    }

    $this->getJson('/api/v1/public/pendaftaran/status?kode=PPDB-2027-0009&tanggal_lahir=2023-02-14')->assertTooManyRequests();
});

it('membuat akun wali untuk kontak utama pendaftar tanpa login saat Kepala Sekolah mengisi NIS, lalu baru memberi notifikasi', function () {
    $pendaftaran = daftarPpdbPublik($this, ['kontak_utama' => 'ayah']);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/verifikasi")->assertOk();
    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/terima")
        ->assertOk()
        ->assertJsonPath('data.wali', null)
        ->assertJsonPath('data.murid.nis', null);
    Notification::assertNotSentTo(User::query()->where('role', 'wali_murid')->get(), PendaftaranDiprosesNotification::class);

    $muridId = $pendaftaran->fresh()?->murid_id;
    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$muridId}/nis", ['nis' => '2027001'])->assertOk();

    $akun = User::query()->where('username', '2027001')->sole();
    $murid = Murid::query()->with('waliMurid')->findOrFail($muridId);
    expect($akun->wajib_ganti_password)->toBeTrue()
        ->and($akun->name)->toBe('Rahmat Hidayat')
        ->and($akun->no_hp)->toBe('081234567891')
        ->and($pendaftaran->fresh()?->wali_murid_id)->toBe($akun->waliMurid?->id)
        ->and($murid->waliMurid->sole()->pivot->hubungan->value)->toBe('ayah')
        ->and($murid->waliMurid->sole()->pivot->is_kontak_utama)->toBeTrue();

    Notification::assertSentTo($akun, PendaftaranDiprosesNotification::class, fn ($notifikasi) => $notifikasi->toDatabase($akun)['pesan']
        === 'Nadia (PPDB-2027-0001) diterima dan sudah tertaut ke akun Anda. Kelasnya akan diinformasikan sekolah.');

    $this->postJson('/api/v1/auth/wali/login', ['username' => '2027001', 'password' => '14022023'])->assertOk();
});

it('tidak mengirim notifikasi saat pendaftaran tanpa login ditolak', function () {
    $pendaftaran = daftarPpdbPublik($this);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/tolak", ['alasan' => 'Berkas tidak lengkap.'])->assertOk();

    Notification::assertNotSentTo(User::query()->where('role', 'wali_murid')->get(), PendaftaranDiprosesNotification::class);
    expect(User::query()->where('role', 'wali_murid')->where('username', '!=', $this->ibuSari->user->username)->exists())->toBeFalse();
});

it('mewajibkan formulir PPDB online lengkap', function (array $timpa, string $field, string $pesan) {
    $this->postJson('/api/v1/public/pendaftaran', dataPendaftaran($timpa))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field => $pesan]);
})->with([
    'kecamatan kosong' => [['kecamatan' => null], 'kecamatan', 'Kecamatan wajib diisi.'],
    'pekerjaan ayah kosong' => [['ayah' => orangTuaPendaftaran(['pekerjaan' => null])], 'ayah.pekerjaan', 'Pekerjaan ayah wajib diisi.'],
    'pekerjaan lainnya tanpa keterangan' => [['ibu' => orangTuaPendaftaran(['pekerjaan' => 'lainnya', 'no_hp' => '081234567890'])], 'ibu.pekerjaan_lainnya', 'Tulis pekerjaan ibu.'],
    'tanpa nomor HP' => [['kontak_utama' => null, 'ayah' => orangTuaPendaftaran(['no_hp' => null]), 'ibu' => orangTuaPendaftaran(['no_hp' => null])], 'ayah.no_hp', 'Isi minimal satu nomor HP ayah, ibu, atau wali.'],
    'kontak utama sudah meninggal' => [['status_yatim_piatu' => 'piatu', 'ibu' => ['nama' => 'Almarhumah Sari']], 'kontak_utama', 'Ibu sudah meninggal menurut status yatim/piatu. Pilih kontak utama lain.'],
    'wali tanpa nomor HP' => [['wali' => ['hubungan' => 'nenek', 'nama' => 'Mbah Sumi']], 'wali.no_hp', 'Nomor HP wali wajib diisi.'],
    'pernyataan tidak dicentang' => [['pernyataan' => false], 'pernyataan', 'Centang pernyataan bahwa data yang diisi benar sebelum mengirim pendaftaran.'],
]);

it('cukup meminta nama ayah yang sudah meninggal di PPDB online', function () {
    $this->postJson('/api/v1/public/pendaftaran', dataPendaftaran(['status_yatim_piatu' => 'yatim', 'ayah' => ['nama' => 'Almarhum Rahmat']]))
        ->assertCreated();
});

it('menyimpan isian formulir PPDB, tidak mengirim NIK di daftar, dan menampilkannya di detail', function () {
    $pendaftaran = daftarPpdbPublik($this);

    $this->actingAs($this->kepsek)->getJson('/api/v1/pendaftaran')->assertOk()->assertJsonMissingPath('data.0.nik');
    $this->actingAs($this->kepsek)->getJson("/api/v1/pendaftaran/{$pendaftaran->id}")
        ->assertOk()
        ->assertJsonPath('data.nik', '3374015402230001')
        ->assertJsonPath('data.desa_kelurahan', 'Pedurungan Kidul')
        ->assertJsonPath('data.imunisasi', ['bcg', 'polio'])
        ->assertJsonPath('data.tinggi_badan', 98.5)
        ->assertJsonPath('data.orang_tua.ibu.pekerjaan_lainnya', 'Guru les')
        ->assertJsonPath('data.orang_tua.wali', null)
        ->assertJsonPath('data.berkas', ['kartu_keluarga' => null, 'akta_kelahiran' => null]);
});

it('menyalin formulir, orang tua, dan centang berkas ke murid saat diterima walau berkas belum lengkap', function () {
    $pendaftaran = daftarPpdbPublik($this);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/pendaftaran/{$pendaftaran->id}/berkas", ['kartu_keluarga' => true])
        ->assertOk()
        ->assertJsonPath('data.berkas.kartu_keluarga.diterima_oleh.id', $this->kepsek->id)
        ->assertJsonPath('data.berkas.akta_kelahiran', null);
    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/verifikasi")->assertOk();
    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/terima")->assertOk();

    $muridId = $pendaftaran->fresh()?->murid_id;
    $this->actingAs($this->kepsek)->getJson("/api/v1/murid/{$muridId}")
        ->assertOk()
        ->assertJsonPath('data.data_lengkap', true)
        ->assertJsonPath('data.kecamatan', 'Pedurungan')
        ->assertJsonPath('data.anak_ke', 1)
        ->assertJsonPath('data.kontak_utama', 'ibu')
        ->assertJsonPath('data.orang_tua.ayah.nama', 'Rahmat Hidayat')
        ->assertJsonPath('data.berkas.kartu_keluarga.diterima_oleh.id', $this->kepsek->id)
        ->assertJsonPath('data.berkas.akta_kelahiran', null);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/pendaftaran/{$pendaftaran->id}/berkas", ['akta_kelahiran' => true])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');
});

it('mencatat pendaftar mundur, menyembunyikannya dari daftar, membebaskan kuota, lalu bisa dikembalikan', function () {
    $pertama = daftarPpdbPublik($this);
    daftarPpdbPublik($this, ['nik' => '3374015402230002', 'nama_lengkap' => 'Raka Pratama', 'nama_panggilan' => 'Raka']);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pertama->id}/mengundurkan-diri", ['alasan' => 'Pindah ke luar kota.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'mengundurkan_diri')
        ->assertJsonPath('data.status_sebelum_mundur', 'diajukan')
        ->assertJsonPath('data.catatan', 'Pindah ke luar kota.');
    Notification::assertNotSentTo(User::query()->where('role', 'wali_murid')->get(), PendaftaranDiprosesNotification::class);

    $this->actingAs($this->kepsek)->getJson('/api/v1/pendaftaran')->assertOk()->assertJsonPath('meta.total', 1);
    $this->actingAs($this->kepsek)->getJson('/api/v1/pendaftaran?filter[status]=mengundurkan_diri')->assertOk()->assertJsonPath('data.*.id', [$pertama->id]);

    $ketiga = daftarPpdbPublik($this, ['nik' => '3374015402230003', 'nama_lengkap' => 'Salsa Aulia', 'nama_panggilan' => 'Salsa']);
    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pertama->id}/batalkan-mundur")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Kuota PPDB tahun ajaran 2027/2028 sudah penuh, jadi pendaftaran PPDB-2027-0001 tidak bisa dikembalikan.');

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$ketiga->id}/tolak", ['alasan' => 'Usia belum cukup.'])->assertOk();
    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pertama->id}/batalkan-mundur")
        ->assertOk()
        ->assertJsonPath('data.status', 'diajukan')
        ->assertJsonPath('data.catatan', null);
    expect(Activity::query()->where('log_name', 'ppdb')->pluck('event')->all())->toBe(['mengundurkan_diri', 'ditolak', 'mundur_dibatalkan']);
});

it('membolehkan anak yang mundur mendaftar lagi dengan NIK yang sama', function () {
    $pendaftaran = daftarPpdbPublik($this);
    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/mengundurkan-diri")->assertOk();

    $this->postJson('/api/v1/public/pendaftaran', dataPendaftaran())->assertCreated();
    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/batalkan-mundur")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Anak dengan NIK ini sudah punya pendaftaran PPDB yang sedang diproses atau sudah diterima.');
});

it('menghapus murid pendaftar yang mundur setelah diterima dan tidak bisa dikembalikan', function () {
    $pendaftaran = daftarPpdbPublik($this);
    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/verifikasi")->assertOk();
    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/terima")->assertOk();
    $muridId = (int) $pendaftaran->fresh()?->murid_id;
    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$muridId}/nis", ['nis' => '2027005'])->assertOk();
    $akun = User::query()->where('username', '2027005')->sole();

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/mengundurkan-diri")
        ->assertOk()
        ->assertJsonPath('data.status', 'mengundurkan_diri')
        ->assertJsonPath('data.murid', null);

    $murid = Murid::withTrashed()->findOrFail($muridId);
    expect($murid->trashed())->toBeTrue()
        ->and($murid->nis)->toBeNull()
        ->and($murid->nik)->toBeNull()
        ->and($akun->fresh()?->status->value)->toBe('nonaktif');

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/batalkan-mundur")->assertStatus(422);
    $this->postJson('/api/v1/public/pendaftaran', dataPendaftaran())->assertCreated();
});

it('menolak mundur setelah diterima kalau murid sudah punya tagihan', function () {
    $pendaftaran = daftarPpdbPublik($this);
    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/verifikasi")->assertOk();
    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/terima")->assertOk();
    Tagihan::factory()->create(['murid_id' => $pendaftaran->fresh()?->murid_id]);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/mengundurkan-diri")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Nadia Putri Rahma sudah punya tagihan, rapor, atau absensi, jadi dianggap sudah mulai sekolah. Ubah status muridnya menjadi Keluar.');
    expect($pendaftaran->fresh()?->status)->toBe(StatusPendaftaran::Diterima);
});
