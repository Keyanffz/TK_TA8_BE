<?php

use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Enums\StatusPendaftaran;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pendaftaran;
use App\Models\Pengaturan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaliMurid;
use App\Notifications\PendaftaranBaruNotification;
use App\Notifications\PendaftaranDiprosesNotification;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
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
        'alamat' => 'Jl. Majapahit No. 12, Pedurungan, Semarang',
        'nama_ayah' => 'Rahmat Hidayat',
        'pekerjaan_ayah' => 'Wiraswasta',
        'nama_ibu' => 'Sari Wulandari',
        'pekerjaan_ibu' => 'Guru',
        'no_hp' => '081234567890',
        'akta_kelahiran' => UploadedFile::fake()->create('akta.pdf', 300, 'application/pdf'),
        'kartu_keluarga' => UploadedFile::fake()->image('kk.jpg', 1200, 900),
        'pas_foto' => UploadedFile::fake()->image('pasfoto.jpg', 300, 400),
        ...$timpa,
    ];
}

function daftarPpdb(object $test, WaliMurid $wali, array $timpa = []): Pendaftaran
{
    $id = $test->actingAs($wali->user)->post('/api/v1/pendaftaran', dataPendaftaran($timpa))->assertCreated()->json('data.id');

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
    $this->actingAs($this->ibuSari->user)->post('/api/v1/pendaftaran', dataPendaftaran())
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'PPDB sedang ditutup. Lihat jadwal pendaftaran di halaman PPDB.');
});

it('menerima pendaftaran dari wali, menyimpan dokumen, dan memberi tahu Kepala Sekolah', function () {
    $response = $this->actingAs($this->ibuSari->user)->post('/api/v1/pendaftaran', dataPendaftaran())
        ->assertCreated()
        ->assertJsonPath('message', 'Pendaftaran Nadia terkirim dengan kode PPDB-2027-0001. Pantau statusnya di menu PPDB.')
        ->assertJsonPath('data.status', 'diajukan')
        ->assertJsonPath('data.tahun_ajaran.nama', '2027/2028')
        ->assertJsonPath('data.hubungan', 'ibu')
        ->assertJsonCount(3, 'data.dokumen');

    $pendaftaran = Pendaftaran::query()->with('dokumen')->findOrFail($response->json('data.id'));
    $akta = $pendaftaran->dokumen->firstWhere('jenis.value', 'akta_kelahiran');
    expect($akta?->path)->toStartWith('ppdb/')->toEndWith('.pdf')
        ->and($pendaftaran->dokumen->firstWhere('jenis.value', 'pas_foto')?->path)->toEndWith('.jpg');
    Storage::disk('local')->assertExists($pendaftaran->dokumen->pluck('path')->all());

    Notification::assertSentTo($this->kepsek, PendaftaranBaruNotification::class, fn ($notifikasi) => $notifikasi->toDatabase($this->kepsek) === [
        'jenis' => 'pendaftaran_baru',
        'judul' => 'Pendaftar PPDB baru',
        'pesan' => 'Nadia Putri Rahma didaftarkan ke Kelompok A (PPDB-2027-0001). Periksa dokumennya.',
        'url' => "/dashboard/ppdb/{$pendaftaran->id}",
    ]);
});

it('menolak pendaftaran saat kuota penuh, tanpa menghitung pendaftaran yang ditolak', function () {
    Pendaftaran::factory()->for($this->tujuan)->create();
    Pendaftaran::factory()->for($this->tujuan)->create(['status' => StatusPendaftaran::Ditolak]);
    daftarPpdb($this, $this->ibuSari);

    $this->actingAs(WaliMurid::factory()->create()->user)->post('/api/v1/pendaftaran', dataPendaftaran(['nik' => '3374015402230002']))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Kuota PPDB tahun ajaran 2027/2028 sudah penuh.');

    expect(Storage::disk('local')->allFiles('ppdb'))->toHaveCount(3);
    $this->getJson('/api/v1/public/ppdb')->assertJsonPath('data.sisa_kuota', 0);
});

it('menolak anak dengan NIK yang punya pendaftaran selain ditolak, di tahun ajaran mana pun', function (StatusPendaftaran $status) {
    Pendaftaran::factory()->create(['nik' => '3374015402230001', 'status' => $status]);

    $this->actingAs($this->ibuSari->user)->post('/api/v1/pendaftaran', dataPendaftaran())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Anak dengan NIK ini sudah punya pendaftaran PPDB yang sedang diproses atau sudah diterima.');
})->with([StatusPendaftaran::Diajukan, StatusPendaftaran::Diverifikasi, StatusPendaftaran::Diterima]);

it('menolak anak yang NIK-nya sudah terdaftar sebagai murid', function () {
    Murid::factory()->create(['nik' => '3374015402230001']);

    $this->actingAs($this->ibuSari->user)->post('/api/v1/pendaftaran', dataPendaftaran())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Anak dengan NIK ini sudah terdaftar sebagai murid. Wali yang sudah punya akun bisa menambahkannya lewat menu Tambah Anak; kalau belum, minta kartu akun ke sekolah.');
});

it('membolehkan pendaftar yang pernah ditolak mendaftar ulang', function () {
    Pendaftaran::factory()->for($this->tujuan)->create(['nik' => '3374015402230001', 'status' => StatusPendaftaran::Ditolak]);

    $this->actingAs($this->ibuSari->user)->post('/api/v1/pendaftaran', dataPendaftaran())->assertCreated();
});

it('memvalidasi data dan dokumen pendaftaran', function (array $timpa, string $field) {
    $this->actingAs($this->ibuSari->user)->post('/api/v1/pendaftaran', dataPendaftaran($timpa))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'tanpa akta' => [['akta_kelahiran' => null], 'akta_kelahiran'],
    'pas foto PDF' => [['pas_foto' => UploadedFile::fake()->create('foto.pdf', 100, 'application/pdf')], 'pas_foto'],
    'dokumen berformat doc' => [['kartu_keluarga' => UploadedFile::fake()->create('kk.docx', 100)], 'kartu_keluarga'],
    'NIK 15 digit' => [['nik' => '337401540223000'], 'nik'],
    'hubungan tidak dikenal' => [['hubungan' => 'kakek'], 'hubungan'],
    'nomor HP salah' => [['no_hp' => '6281234'], 'no_hp'],
]);

it('menjalankan alur verifikasi lalu terima: membuat murid, menautkan wali, menyalin foto, dan menempatkan di kelas', function () {
    $pendaftaran = daftarPpdb($this, $this->ibuSari);
    $kelas = Kelas::factory()->for($this->tujuan)->create(['nama' => 'TK A1']);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/verifikasi")
        ->assertOk()
        ->assertJsonPath('data.status', 'diverifikasi');
    Notification::assertSentTo($this->ibuSari->user, PendaftaranDiprosesNotification::class, fn ($notifikasi) => $notifikasi->toDatabase($this->ibuSari->user)['pesan']
        === 'Dokumen pendaftaran Nadia (PPDB-2027-0001) sudah diverifikasi. Tunggu keputusan akhir dari sekolah.');

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/terima", ['kelas_id' => $kelas->id])
        ->assertOk()
        ->assertJsonPath('message', 'Nadia Putri Rahma diterima sebagai murid.')
        ->assertJsonPath('data.status', 'diterima')
        ->assertJsonPath('data.murid.nis', 'TA20270001');

    $murid = Murid::query()->with(['waliMurid', 'kelas'])->where('nis', 'TA20270001')->sole();
    expect($murid->nama_lengkap)->toBe('Nadia Putri Rahma')
        ->and($murid->status)->toBe(StatusMurid::Aktif)
        ->and($murid->tanggal_masuk->toDateString())->toBe('2027-07-12')
        ->and($murid->nik)->toBe('3374015402230001')
        ->and($murid->foto_path)->toStartWith('murid/')
        ->and($murid->waliMurid->sole()->id)->toBe($this->ibuSari->id)
        ->and($murid->waliMurid->sole()->pivot->hubungan->value)->toBe('ibu')
        ->and($murid->waliMurid->sole()->pivot->is_kontak_utama)->toBeTrue()
        ->and($murid->kelas->sole()->id)->toBe($kelas->id)
        ->and($murid->kelas->sole()->pivot->status)->toBe(StatusKelasMurid::Aktif)
        ->and(User::query()->where('username', 'TA20270001')->exists())->toBeFalse();
    Storage::disk('local')->assertExists((string) $murid->foto_path);

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
        ->and($pendaftaran->fresh()?->status)->toBe(StatusPendaftaran::Diverifikasi)
        ->and(Storage::disk('local')->allFiles('murid'))->toBe([]);
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
    $this->actingAs($this->ibuSari->user)->getJson("/api/v1/pendaftaran/{$milikSari->id}")->assertOk()->assertJsonCount(3, 'data.dokumen');

    $this->actingAs($this->kepsek)->getJson('/api/v1/pendaftaran?filter[status]=diajukan')->assertJsonPath('meta.total', 2);
    $this->actingAs($this->kepsek)->getJson('/api/v1/pendaftaran?search=Nadia')->assertJsonPath('meta.total', 1);
});

it('menutup PPDB untuk guru dan menolak wali memproses pendaftaran', function () {
    $pendaftaran = Pendaftaran::factory()->for($this->tujuan)->create();
    $guru = buatGuru()->user;

    $this->actingAs($guru)->getJson('/api/v1/pendaftaran')->assertForbidden();
    $this->actingAs($guru)->post('/api/v1/pendaftaran', dataPendaftaran())->assertForbidden();
    $this->actingAs($this->ibuSari->user)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/verifikasi")->assertForbidden();
    $this->actingAs(User::factory()->superAdmin()->create())->post('/api/v1/pendaftaran', dataPendaftaran())->assertForbidden();
});

function daftarPpdbPublik(object $test, array $timpa = []): Pendaftaran
{
    $kode = $test->post('/api/v1/public/pendaftaran', dataPendaftaran($timpa))->assertCreated()->json('data.kode');

    return Pendaftaran::query()->where('kode', $kode)->sole();
}

it('menerima pendaftaran tanpa login dan membalas kode pendaftaran', function () {
    $this->post('/api/v1/public/pendaftaran', dataPendaftaran())
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

    $pendaftaran = Pendaftaran::query()->with('dokumen')->sole();
    expect($pendaftaran->wali_murid_id)->toBeNull()
        ->and($pendaftaran->hubungan->value)->toBe('ibu')
        ->and($pendaftaran->dokumen)->toHaveCount(3);
    Notification::assertSentTo($this->kepsek, PendaftaranBaruNotification::class);

    $this->actingAs($this->kepsek)->getJson("/api/v1/pendaftaran/{$pendaftaran->id}")
        ->assertOk()
        ->assertJsonPath('data.wali', null);
});

it('menerapkan aturan jadwal, kuota, dan NIK dobel pada pendaftaran tanpa login', function () {
    daftarPpdbPublik($this);

    $this->post('/api/v1/public/pendaftaran', dataPendaftaran())
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Anak dengan NIK ini sudah punya pendaftaran PPDB yang sedang diproses atau sudah diterima.');

    aturPpdb(['ppdb.dibuka' => false]);
    $this->post('/api/v1/public/pendaftaran', dataPendaftaran(['nik' => '3374015402230002']))
        ->assertStatus(422)
        ->assertJsonPath('message', 'PPDB sedang ditutup. Lihat jadwal pendaftaran di halaman PPDB.');
});

it('membatasi pendaftaran tanpa login 3 kali per jam per IP', function () {
    aturPpdb(['ppdb.kuota' => 10]);

    foreach (['3374015402230001', '3374015402230002', '3374015402230003'] as $nik) {
        $this->post('/api/v1/public/pendaftaran', dataPendaftaran(['nik' => $nik]))->assertCreated();
    }

    $this->post('/api/v1/public/pendaftaran', dataPendaftaran(['nik' => '3374015402230004']))
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->post('/api/v1/public/pendaftaran', dataPendaftaran(['nik' => '3374015402230004']))
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

it('membuat akun wali saat pendaftaran tanpa login diterima dan baru memberi notifikasi setelah akun ada', function () {
    $pendaftaran = daftarPpdbPublik($this, ['hubungan' => 'ayah']);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/verifikasi")->assertOk();
    Notification::assertNotSentTo(User::query()->where('role', 'wali_murid')->get(), PendaftaranDiprosesNotification::class);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/terima")
        ->assertOk()
        ->assertJsonPath('data.wali.username', 'TA20270001')
        ->assertJsonPath('data.wali.no_hp', '081234567890');

    $akun = User::query()->where('username', 'TA20270001')->sole();
    $murid = Murid::query()->with('waliMurid')->where('nis', 'TA20270001')->sole();
    expect($akun->wajib_ganti_password)->toBeTrue()
        ->and($akun->name)->toBe('Wali Nadia')
        ->and($pendaftaran->fresh()?->wali_murid_id)->toBe($akun->waliMurid?->id)
        ->and($murid->waliMurid->sole()->pivot->hubungan->value)->toBe('ayah')
        ->and($murid->waliMurid->sole()->pivot->is_kontak_utama)->toBeTrue();

    Notification::assertSentTo($akun, PendaftaranDiprosesNotification::class);

    $this->postJson('/api/v1/auth/wali/login', ['username' => 'TA20270001', 'password' => '14022023'])->assertOk();
});

it('tidak mengirim notifikasi saat pendaftaran tanpa login ditolak', function () {
    $pendaftaran = daftarPpdbPublik($this);

    $this->actingAs($this->kepsek)->postJson("/api/v1/pendaftaran/{$pendaftaran->id}/tolak", ['alasan' => 'Dokumen tidak terbaca.'])->assertOk();

    Notification::assertNotSentTo(User::query()->where('role', 'wali_murid')->get(), PendaftaranDiprosesNotification::class);
    expect(User::query()->where('role', 'wali_murid')->where('username', '!=', $this->ibuSari->user->username)->exists())->toBeFalse();
});
