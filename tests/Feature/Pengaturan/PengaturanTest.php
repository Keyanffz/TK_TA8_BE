<?php

use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Models\Guru;
use App\Models\Pengaturan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('public');
    $this->kepsek = buatKepalaSekolah();
    $this->seed(PengaturanSeeder::class);
});

function unggahGambarPengaturan(object $test): string
{
    return $test->actingAs($test->kepsek)->post('/api/v1/pengaturan/upload', ['gambar' => UploadedFile::fake()->image('logo.png', 400, 400)])
        ->assertCreated()
        ->json('data.path');
}

it('menampilkan pengaturan sebagai objek datar berkunci lengkap dengan pasangan url gambar', function () {
    $data = $this->actingAs($this->kepsek)->getJson('/api/v1/pengaturan')->assertOk()->json('data');

    expect($data)->toHaveCount(35)
        ->and($data['profil.nama_sekolah'])->toBe('TK Tarbiyathul Athfal 8')
        ->and($data)->toHaveKey('profil.logo', null)
        ->and($data)->toHaveKey('profil.logo_url', null)
        ->and($data['landing.hero'])->toHaveKey('gambar_url', null)
        ->and($data['keuangan.tanggal_jatuh_tempo'])->toBe(10);

    $ppdb = $this->actingAs($this->kepsek)->getJson('/api/v1/pengaturan?grup=ppdb')->assertOk()->json('data');
    expect($ppdb)->toHaveCount(6)
        ->and($ppdb['ppdb.dibuka'])->toBeFalse();
});

it('hanya mengizinkan guru berizin keuangan membaca grup keuangan', function () {
    $bendahara = Guru::factory()->kelolaKeuangan()->create()->user;

    $this->actingAs($bendahara)->getJson('/api/v1/pengaturan?grup=keuangan')->assertOk()->assertJsonCount(3, 'data');
    $this->actingAs($bendahara)->getJson('/api/v1/pengaturan?grup=profil')->assertForbidden();
    $this->actingAs($bendahara)->getJson('/api/v1/pengaturan')->assertForbidden();
    $this->actingAs(buatGuru()->user)->getJson('/api/v1/pengaturan?grup=keuangan')->assertForbidden();
    $this->actingAs($bendahara)->putJson('/api/v1/pengaturan', ['items' => ['keuangan.hari_pengingat' => 5]])->assertForbidden();
});

it('menyimpan sebagian kunci, menyanitasi HTML, dan mencatat log aktivitas', function () {
    $data = $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => [
        'profil.visi' => 'Anak beriman dan mandiri.',
        'profil.sejarah' => '<p>Berdiri tahun 1985.</p><script>alert(1)</script>',
        'keuangan.tanggal_jatuh_tempo' => '15',
        'landing.program' => [['judul' => 'Kelompok A', 'deskripsi' => 'Usia 4–5 tahun.', 'ikon' => 'blocks']],
    ]])
        ->assertOk()
        ->assertJsonPath('message', 'Pengaturan tersimpan.')
        ->json('data');

    expect($data['profil.visi'])->toBe('Anak beriman dan mandiri.')
        ->and($data['profil.sejarah'])->toBe('<p>Berdiri tahun 1985.</p>')
        ->and($data['keuangan.tanggal_jatuh_tempo'])->toBe(15)
        ->and($data['landing.program'])->toHaveCount(1)
        ->and($data['profil.nama_sekolah'])->toBe('TK Tarbiyathul Athfal 8');

    expect(app(PengaturanService::class)->nilai('keuangan.tanggal_jatuh_tempo'))->toBe(15);
    $log = Activity::query()->where('log_name', 'pengaturan')->sole();
    expect($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->properties['kunci'])->toBe(['profil.visi', 'profil.sejarah', 'keuangan.tanggal_jatuh_tempo', 'landing.program']);
});

it('memvalidasi nilai per kunci dan menolak kunci yang tidak dikenal', function (array $items, string $field) {
    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => $items])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors([$field]);
})->with([
    'kunci asing' => [['profil.warna_tema' => 'hijau'], 'items'],
    'jatuh tempo 31' => [['keuangan.tanggal_jatuh_tempo' => 31], 'keuangan.tanggal_jatuh_tempo'],
    'nama sekolah kosong' => [['profil.nama_sekolah' => ''], 'profil.nama_sekolah'],
    'email salah' => [['profil.email' => 'bukan-email'], 'profil.email'],
    'misi bukan daftar' => [['profil.misi' => 'satu misi'], 'profil.misi'],
    'program tanpa judul' => [['landing.program' => [['deskripsi' => 'x', 'ikon' => 'blocks']]], 'landing.program.0.judul'],
    'ikon berisi spasi' => [['landing.keunggulan' => [['judul' => 'Guru', 'ikon' => 'user check']]], 'landing.keunggulan.0.ikon'],
    'rekening tanpa nomor' => [['keuangan.rekening' => [['bank' => 'BRI', 'atas_nama' => 'TK']]], 'keuangan.rekening.0.nomor'],
    'dibuka bukan boolean' => [['ppdb.dibuka' => 'ya'], 'ppdb.dibuka'],
    'gambar belum diunggah' => [['profil.logo' => 'pengaturan/tidak-ada.jpg'], 'profil.logo'],
    'tahun ajaran PPDB tidak ada' => [['ppdb.tahun_ajaran_id' => 999], 'ppdb.tahun_ajaran_id'],
    'info wali aktif tanpa judul' => [['beranda.info_wali' => ['aktif' => true, 'judul' => '', 'isi' => 'Libur.', 'nada' => 'info', 'berlaku_sampai' => null]], 'beranda.info_wali.judul'],
    'nada info wali asing' => [['beranda.info_wali' => ['aktif' => false, 'nada' => 'darurat']], 'beranda.info_wali.nada'],
    'field info wali asing' => [['beranda.info_wali' => ['aktif' => false, 'nada' => 'info', 'warna' => 'merah']], 'beranda.info_wali'],
    'berlaku sampai bukan tanggal' => [['beranda.info_wali' => ['aktif' => false, 'nada' => 'info', 'berlaku_sampai' => '31/10/2026']], 'beranda.info_wali.berlaku_sampai'],
    'lokasi absensi tanpa longitude' => [['absensi.lokasi' => ['latitude' => -6.99]], 'absensi.lokasi'],
    'latitude di luar rentang' => [['absensi.lokasi' => ['latitude' => -96.99, 'longitude' => 110.42]], 'absensi.lokasi.latitude'],
    'radius nol' => [['absensi.radius_meter' => 0], 'absensi.radius_meter'],
    'batas akurasi bukan angka' => [['absensi.batas_akurasi_meter' => 'dekat'], 'absensi.batas_akurasi_meter'],
    'jam masuk bukan HH:MM' => [['absensi.jam_masuk' => ['buka' => '6.30', 'batas_terlambat' => '07:15', 'tutup' => '09:00']], 'absensi.jam_masuk.buka'],
    'jam masuk tanpa batas terlambat' => [['absensi.jam_masuk' => ['buka' => '06:30', 'tutup' => '09:00']], 'absensi.jam_masuk.batas_terlambat'],
    'batas terlambat setelah jam tutup' => [['absensi.jam_masuk' => ['buka' => '06:30', 'batas_terlambat' => '09:30', 'tutup' => '09:00']], 'absensi.jam_masuk'],
    'jam pulang tutup sebelum buka' => [['absensi.jam_pulang' => ['buka' => '13:00', 'tutup' => '12:00']], 'absensi.jam_pulang'],
    'hari kerja kosong' => [['absensi.hari_kerja' => []], 'absensi.hari_kerja'],
    'hari kerja di luar 1-7' => [['absensi.hari_kerja' => [1, 8]], 'absensi.hari_kerja.1'],
    'hari kerja dobel' => [['absensi.hari_kerja' => [1, 1]], 'absensi.hari_kerja.0'],
    'tanggal libur bukan tanggal' => [['absensi.tanggal_libur' => ['17 Agustus']], 'absensi.tanggal_libur.0'],
    'masa simpan foto nol' => [['absensi.masa_simpan_foto_bulan' => 0], 'absensi.masa_simpan_foto_bulan'],
    'tanggal mulai absensi kosong' => [['absensi.tanggal_mulai' => null], 'absensi.tanggal_mulai'],
    'tanggal mulai absensi bukan tanggal' => [['absensi.tanggal_mulai' => '1 Oktober'], 'absensi.tanggal_mulai'],
]);

it('menyimpan pengaturan absensi dan menampilkannya di grup absensi', function () {
    $data = $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => [
        'absensi.lokasi' => ['latitude' => '-6.9903', 'longitude' => 110.4229],
        'absensi.radius_meter' => '150',
        'absensi.jam_masuk' => ['buka' => '06:45', 'batas_terlambat' => '07:30', 'tutup' => '08:30'],
        'absensi.hari_kerja' => [5, 1, 3, 2, 4],
        'absensi.tanggal_libur' => ['2026-12-25', '2026-10-26'],
        'absensi.tanggal_mulai' => '2026-10-05',
    ]])->assertOk()->json('data');

    expect($data['absensi.lokasi'])->toBe(['latitude' => -6.9903, 'longitude' => 110.4229])
        ->and($data['absensi.radius_meter'])->toBe(150)
        ->and($data['absensi.hari_kerja'])->toBe([1, 2, 3, 4, 5])
        ->and($data['absensi.tanggal_libur'])->toBe(['2026-10-26', '2026-12-25']);

    $this->getJson('/api/v1/pengaturan?grup=absensi')
        ->assertOk()
        ->assertJsonCount(9, 'data')
        ->assertJsonPath('data', [
            'absensi.batas_akurasi_meter' => 100,
            'absensi.hari_kerja' => [1, 2, 3, 4, 5],
            'absensi.jam_masuk' => ['buka' => '06:45', 'batas_terlambat' => '07:30', 'tutup' => '08:30'],
            'absensi.jam_pulang' => ['buka' => '11:00', 'tutup' => '15:00'],
            'absensi.lokasi' => ['latitude' => -6.9903, 'longitude' => 110.4229],
            'absensi.masa_simpan_foto_bulan' => 6,
            'absensi.radius_meter' => 150,
            'absensi.tanggal_libur' => ['2026-10-26', '2026-12-25'],
            'absensi.tanggal_mulai' => '2026-10-05',
        ]);
    $this->getJson('/api/v1/public/profil')->assertJsonMissingPath('data.absensi.lokasi');
});

it('mengisi tanggal mulai absensi dengan tanggal migration dijalankan', function () {
    $mulai = Pengaturan::query()->where('kunci', 'absensi.tanggal_mulai')->sole();

    expect($mulai->nilai)->toBe(Carbon::now()->toDateString())
        ->and($mulai->grup)->toBe('absensi');
});

/**
 * Senin, 28 September 2026 sudah lewat dan sudah ditandai scheduler: Bu Nur dan Bu Dwi tidak hadir otomatis,
 * tidak hadir Bu Sri sudah dikoreksi Kepala Sekolah, Bu Endang absen sungguhan dengan foto, dan absen Bu Rina
 * dikoreksi menjadi tidak hadir. Selasa 29 September juga punya satu tidak hadir otomatis.
 */
function siapkanAbsensiSebelumLibur(object $test): array
{
    $senin = ['tanggal' => '2026-09-28'];
    $dikoreksi = ['catatan_koreksi' => 'Dinas luar.', 'dikoreksi_oleh' => $test->kepsek->id, 'dikoreksi_at' => '2026-09-29 08:00:00'];

    return [
        'nur' => Absensi::factory()->tidakHadir()->create($senin),
        'dwi' => Absensi::factory()->tidakHadir()->create($senin),
        'sri' => Absensi::factory()->tidakHadir()->create([...$senin, ...$dikoreksi]),
        'endang' => Absensi::factory()->create([...$senin, 'waktu' => '2026-09-28 06:55:00', 'foto_path' => 'absensi/endang.jpg']),
        'endang_pulang' => Absensi::factory()->pulang()->create([...$senin, 'waktu' => '2026-09-28 12:05:00']),
        'rina' => Absensi::factory()->create([...$senin, 'waktu' => '2026-09-28 07:40:00', 'status' => StatusAbsensi::TidakHadir, ...$dikoreksi]),
        'selasa' => Absensi::factory()->tidakHadir()->create(['tanggal' => '2026-09-29']),
    ];
}

it('menghapus tidak hadir otomatis yang belum dikoreksi saat tanggal libur ditambahkan, dan mencatatnya di log', function () {
    $absensi = siapkanAbsensiSebelumLibur($this);

    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['absensi.tanggal_libur' => ['2026-09-28']]])->assertOk();

    expect(Absensi::query()->whereKey([$absensi['nur']->id, $absensi['dwi']->id])->count())->toBe(0);
    $log = Activity::query()->where('log_name', 'absensi')->where('event', 'tidak_hadir_dihapus')->sole();
    expect($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->properties->all())->toBe(['tanggal_libur' => ['2026-09-28'], 'jumlah' => 2])
        ->and($log->description)->toBe('Menghapus 2 tanda tidak hadir otomatis pada tanggal libur yang baru ditambahkan');
});

it('tidak menghapus absen sungguhan, baris yang sudah dikoreksi, dan tidak hadir di tanggal lain', function () {
    $absensi = siapkanAbsensiSebelumLibur($this);

    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['absensi.tanggal_libur' => ['2026-09-28']]])->assertOk();

    foreach (['sri', 'endang', 'endang_pulang', 'rina', 'selasa'] as $nama) {
        expect($absensi[$nama]->fresh())->not->toBeNull();
    }
    expect($absensi['sri']->fresh()?->status)->toBe(StatusAbsensi::TidakHadir)
        ->and($absensi['endang']->fresh()?->foto_path)->toBe('absensi/endang.jpg');
});

it('hanya membersihkan tanggal libur yang baru ditambahkan, bukan yang sudah ada di daftar', function () {
    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['absensi.tanggal_libur' => ['2026-09-28']]])->assertOk();
    $absensi = siapkanAbsensiSebelumLibur($this);

    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['absensi.tanggal_libur' => ['2026-09-28', '2026-09-29']]])->assertOk();

    expect($absensi['nur']->fresh())->not->toBeNull()
        ->and($absensi['selasa']->fresh())->toBeNull()
        ->and(Activity::query()->where('event', 'tidak_hadir_dihapus')->sole()->properties['jumlah'])->toBe(1);
});

it('tidak mencatat log penghapusan kalau tanggal libur baru tidak punya tidak hadir otomatis', function () {
    siapkanAbsensiSebelumLibur($this);

    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['absensi.tanggal_libur' => ['2026-12-25'], 'absensi.radius_meter' => 150]])->assertOk();
    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['absensi.radius_meter' => 120]])->assertOk();

    expect(Absensi::query()->count())->toBe(7)
        ->and(Activity::query()->where('event', 'tidak_hadir_dihapus')->count())->toBe(0);
});

it('hanya mengizinkan Kepala Sekolah membaca dan mengubah pengaturan absensi', function () {
    $guru = buatGuru()->user;
    $bendahara = Guru::factory()->kelolaKeuangan()->create()->user;

    $this->actingAs($guru)->getJson('/api/v1/pengaturan?grup=absensi')->assertForbidden();
    $this->actingAs($bendahara)->getJson('/api/v1/pengaturan?grup=absensi')->assertForbidden();
    $this->actingAs($guru)->putJson('/api/v1/pengaturan', ['items' => ['absensi.radius_meter' => 5000]])->assertForbidden();

    expect(app(PengaturanService::class)->nilai('absensi.radius_meter'))->toBe(100);
});

it('menyimpan banner info wali dan menampilkannya di grup beranda', function () {
    $info = ['aktif' => true, 'judul' => 'Libur Maulid Nabi', 'isi' => 'Sekolah libur Senin, 26 Oktober 2026.', 'nada' => 'penting', 'berlaku_sampai' => '2026-10-26'];

    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['beranda.info_wali' => $info]])->assertOk();

    $this->getJson('/api/v1/pengaturan?grup=beranda')
        ->assertOk()
        ->assertExactJson(['success' => true, 'message' => 'Berhasil', 'data' => ['beranda.info_wali' => $info], 'meta' => null]);
    $this->getJson('/api/v1/public/profil')->assertJsonMissingPath('data.beranda.info_wali');
});

it('menerima banner info wali nonaktif tanpa judul dan isi', function () {
    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['beranda.info_wali' => [
        'aktif' => false, 'judul' => null, 'isi' => null, 'nada' => 'info', 'berlaku_sampai' => null,
    ]]])->assertOk();
});

it('memakai nama field yang mudah dibaca di pesan validasi', function () {
    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['profil.nama_sekolah' => '']])
        ->assertJsonPath('errors', ['profil.nama_sekolah' => ['Nama sekolah wajib diisi.']]);
});

it('menolak tanggal tutup PPDB sebelum tanggal buka, juga terhadap nilai yang sudah tersimpan', function () {
    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['ppdb.tanggal_buka' => '2026-09-01']])->assertOk();

    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['ppdb.tanggal_tutup' => '2026-08-31']])
        ->assertStatus(422)
        ->assertJsonPath('errors', ['ppdb.tanggal_tutup' => ['Tanggal tutup PPDB tidak boleh sebelum tanggal buka.']]);
});

it('menolak membuka PPDB sebelum tahun ajaran tujuan dipilih', function () {
    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['ppdb.dibuka' => true]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'PPDB tidak bisa dibuka sebelum tahun ajaran tujuan PPDB dipilih.');

    $tujuan = TahunAjaran::factory()->create(['nama' => '2027/2028']);
    $data = $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['ppdb.dibuka' => true, 'ppdb.tahun_ajaran_id' => $tujuan->id]])
        ->assertOk()
        ->json('data');
    expect($data['ppdb.dibuka'])->toBeTrue()
        ->and($data['ppdb.tahun_ajaran_id'])->toBe($tujuan->id);
});

it('mengunggah gambar lalu memakainya sebagai logo dan gambar hero, mengabaikan field url', function () {
    $logo = unggahGambarPengaturan($this);
    $hero = unggahGambarPengaturan($this);
    expect($logo)->toStartWith('pengaturan/');
    Storage::disk('public')->assertExists($logo);

    $data = $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => [
        'profil.logo' => $logo,
        'profil.logo_url' => 'https://contoh.test/palsu.png',
        'landing.hero' => ['judul' => 'TK Tarbiyathul Athfal 8', 'subjudul' => null, 'gambar' => $hero, 'gambar_url' => 'x', 'cta_teks' => 'Lihat Info PPDB'],
    ]])
        ->assertOk()
        ->json('data');

    expect($data['profil.logo'])->toBe($logo)
        ->and($data['profil.logo_url'])->toBe(Storage::disk('public')->url($logo))
        ->and($data['landing.hero']['gambar_url'])->toBe(Storage::disk('public')->url($hero));
    expect(Pengaturan::query()->where('kunci', 'landing.hero')->value('nilai'))->not->toHaveKey('gambar_url');
});

it('menghapus file gambar yang tidak dipakai lagi setelah diganti', function () {
    $lama = unggahGambarPengaturan($this);
    $baru = unggahGambarPengaturan($this);
    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['profil.logo' => $lama]])->assertOk();

    $this->actingAs($this->kepsek)->putJson('/api/v1/pengaturan', ['items' => ['profil.logo' => $baru]])->assertOk();

    Storage::disk('public')->assertMissing($lama);
    Storage::disk('public')->assertExists($baru);
});

it('hanya Kepala Sekolah yang bisa mengubah pengaturan dan mengunggah gambar', function () {
    $wali = User::factory()->waliMurid()->create();

    $this->actingAs($wali)->getJson('/api/v1/pengaturan')->assertForbidden();
    $this->actingAs(buatGuru()->user)->post('/api/v1/pengaturan/upload', ['gambar' => UploadedFile::fake()->image('a.png')])->assertForbidden();
});

it('membuang cache pengaturan saat baris pengaturan diubah langsung', function () {
    $service = app(PengaturanService::class);
    expect($service->nilai('keuangan.hari_pengingat'))->toBe(3);

    Pengaturan::query()->where('kunci', 'keuangan.hari_pengingat')->firstOrFail()->update(['nilai' => 5]);

    expect($service->nilai('keuangan.hari_pengingat'))->toBe(5);
});
