<?php

use App\Models\Guru;
use App\Models\Pengaturan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Http\UploadedFile;
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

    expect($data)->toHaveCount(26)
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
]);

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
