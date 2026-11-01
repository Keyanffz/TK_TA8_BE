<?php

use App\Enums\Hubungan;
use App\Enums\TargetPengumuman;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pengumuman;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * TK A1 diampu Bu Aini, TK A2 diampu Bu Dwi. Aisyah (TK A1) tertaut ke ibunya, Bima (TK A2) ke ayahnya.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    Storage::fake('local');
    Notification::fake();

    $this->kepsek = buatKepalaSekolah();
    $tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->buAini = buatGuru();
    $this->buDwi = buatGuru();
    $this->kelasA1 = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id]);
    $this->kelasA2 = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK A2', 'wali_kelas_id' => $this->buDwi->id]);

    $aisyah = Murid::factory()->create(['nama_lengkap' => 'Aisyah Putri']);
    $bima = Murid::factory()->create(['nama_lengkap' => 'Bima Saputra']);
    $this->kelasA1->murid()->attach($aisyah);
    $this->kelasA2->murid()->attach($bima);
    $this->ibuAisyah = WaliMurid::factory()->create();
    $this->ayahBima = WaliMurid::factory()->create();
    $aisyah->waliMurid()->attach($this->ibuAisyah, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $bima->waliMurid()->attach($this->ayahBima, ['hubungan' => Hubungan::Ayah, 'is_kontak_utama' => true]);
});

/**
 * Isian multipart: boolean dikirim sebagai "1"/"0" seperti dari FE.
 */
function isianPengumuman(array $timpa = []): array
{
    return [
        'judul' => 'Libur semester ganjil',
        'isi' => '<p>Sekolah libur mulai 22 Desember sampai 2 Januari.</p>',
        'target' => TargetPengumuman::Semua->value,
        'publish' => '1',
        ...$timpa,
    ];
}

function lampiranPdf(string $nama = 'Surat Edaran Libur.pdf', int $kilobyte = 300): UploadedFile
{
    return UploadedFile::fake()->create($nama, $kilobyte, 'application/pdf');
}

it('menyimpan lampiran PDF saat Kepala Sekolah membuat pengumuman', function () {
    $response = $this->actingAs($this->kepsek)->post('/api/v1/pengumuman', isianPengumuman(['lampiran' => lampiranPdf()]))
        ->assertCreated()
        ->assertJsonPath('data.lampiran.nama', 'Surat Edaran Libur.pdf')
        ->assertJsonPath('data.lampiran.jenis', 'pdf');

    $pengumuman = Pengumuman::query()->sole();
    expect($pengumuman->lampiran_path)->toStartWith('pengumuman/')->toEndWith('.pdf')
        ->and($response->json('data.lampiran.ukuran'))->toBe(Storage::disk('local')->size($pengumuman->lampiran_path))
        ->and($response->json('data.lampiran.url'))->toContain('/api/v1/media/');
    Storage::disk('local')->assertExists($pengumuman->lampiran_path);
});

it('mengecilkan lampiran gambar menjadi JPEG dan menyesuaikan ekstensi namanya', function () {
    $this->actingAs($this->kepsek)->post('/api/v1/pengumuman', isianPengumuman([
        'lampiran' => UploadedFile::fake()->image('Jadwal Lomba Mewarnai.png', 2400, 1800),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.lampiran.nama', 'Jadwal Lomba Mewarnai.jpg')
        ->assertJsonPath('data.lampiran.jenis', 'gambar');

    $path = (string) Pengumuman::query()->sole()->lampiran_path;
    expect($path)->toEndWith('.jpg')
        ->and(getimagesizefromstring((string) Storage::disk('local')->get($path))[0])->toBe(1600);
});

it('menolak lampiran yang bukan PDF atau gambar dan lampiran di atas 5 MB', function (UploadedFile $lampiran, string $pesan) {
    $this->actingAs($this->kepsek)->post('/api/v1/pengumuman', isianPengumuman(['lampiran' => $lampiran]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.lampiran.0', $pesan);

    expect(Pengumuman::query()->count())->toBe(0);
})->with([
    'dokumen Word' => [UploadedFile::fake()->create('Daftar Hadir.docx', 40, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), 'Lampiran harus berupa PDF atau gambar (JPG, PNG, WebP).'],
    'PDF 6 MB' => [UploadedFile::fake()->create('Brosur.pdf', 6 * 1024, 'application/pdf'), 'Ukuran lampiran maksimal 5 MB.'],
]);

it('mempertahankan lampiran saat pengumuman diubah tanpa lampiran baru lewat JSON', function () {
    $this->actingAs($this->kepsek)->post('/api/v1/pengumuman', isianPengumuman(['lampiran' => lampiranPdf()]))->assertCreated();
    $pengumuman = Pengumuman::query()->sole();

    $this->actingAs($this->kepsek)->putJson("/api/v1/pengumuman/{$pengumuman->id}", isianPengumuman(['judul' => 'Libur semester ganjil diundur', 'publish' => true]))
        ->assertOk()
        ->assertJsonPath('data.lampiran.nama', 'Surat Edaran Libur.pdf');

    Storage::disk('local')->assertExists((string) $pengumuman->refresh()->lampiran_path);
});

it('mengganti lampiran dan menghapus file lama, lalu menghapus lampiran dengan hapus_lampiran', function () {
    $this->actingAs($this->kepsek)->post('/api/v1/pengumuman', isianPengumuman(['lampiran' => lampiranPdf('Surat lama.pdf')]))->assertCreated();
    $pengumuman = Pengumuman::query()->sole();
    $pathLama = (string) $pengumuman->lampiran_path;

    $this->actingAs($this->kepsek)->put("/api/v1/pengumuman/{$pengumuman->id}", isianPengumuman(['lampiran' => lampiranPdf('Surat revisi.pdf')]))
        ->assertOk()
        ->assertJsonPath('data.lampiran.nama', 'Surat revisi.pdf');
    $pathBaru = (string) $pengumuman->refresh()->lampiran_path;
    Storage::disk('local')->assertMissing($pathLama);
    Storage::disk('local')->assertExists($pathBaru);

    $this->actingAs($this->kepsek)->put("/api/v1/pengumuman/{$pengumuman->id}", isianPengumuman(['hapus_lampiran' => '1']))
        ->assertOk()
        ->assertJsonPath('data.lampiran', null);
    Storage::disk('local')->assertMissing($pathBaru);
    expect($pengumuman->refresh()->only(['lampiran_path', 'lampiran_nama', 'lampiran_ukuran']))
        ->toBe(['lampiran_path' => null, 'lampiran_nama' => null, 'lampiran_ukuran' => null]);
});

it('menolak lampiran baru yang dikirim bersama hapus_lampiran', function () {
    $this->actingAs($this->kepsek)->post('/api/v1/pengumuman', isianPengumuman(['lampiran' => lampiranPdf()]))->assertCreated();
    $pengumuman = Pengumuman::query()->sole();

    $this->actingAs($this->kepsek)->put("/api/v1/pengumuman/{$pengumuman->id}", isianPengumuman(['lampiran' => lampiranPdf('Surat revisi.pdf'), 'hapus_lampiran' => '1']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.lampiran.0', 'Pilih salah satu: ganti lampiran atau hapus lampiran.');

    expect($pengumuman->refresh()->lampiran_nama)->toBe('Surat Edaran Libur.pdf');
});

it('memberi url lampiran hanya kepada sasaran pengumuman kelas', function () {
    $this->actingAs($this->buAini->user)->post('/api/v1/pengumuman', isianPengumuman([
        'judul' => 'Membawa bekal sayur',
        'target' => TargetPengumuman::Kelas->value,
        'kelas_ids' => [$this->kelasA1->id],
        'lampiran' => lampiranPdf('Menu bekal.pdf'),
    ]))->assertCreated();
    $pengumuman = Pengumuman::query()->sole();

    expect($this->actingAs($this->ibuAisyah->user)->getJson("/api/v1/pengumuman/{$pengumuman->id}")->assertOk()->json('data.lampiran.url'))
        ->toContain('/api/v1/media/');
    $this->actingAs($this->ayahBima->user)->getJson("/api/v1/pengumuman/{$pengumuman->id}")->assertNotFound();
});

it('membuka lampiran pengumuman publik tanpa login lewat alamat tetap', function () {
    $this->actingAs($this->kepsek)->post('/api/v1/pengumuman', isianPengumuman(['is_publik' => '1', 'lampiran' => lampiranPdf('Brosur PPDB.pdf')]))->assertCreated();
    $publik = Pengumuman::query()->sole();
    app('auth')->forgetGuards();

    $url = $this->getJson("/api/v1/public/pengumuman/{$publik->slug}")->assertOk()->json('data.lampiran.url');
    expect($url)->toEndWith("/api/v1/public/pengumuman/{$publik->slug}/lampiran");

    $this->get("/api/v1/public/pengumuman/{$publik->slug}/lampiran")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline; filename="Brosur PPDB.pdf"')
        ->assertHeader('cache-control', 'no-cache, private');
});

it('menolak lampiran pengumuman yang bukan publik, masih draft, atau tidak punya lampiran', function () {
    $this->actingAs($this->kepsek)->post('/api/v1/pengumuman', isianPengumuman(['judul' => 'Rapat wali murid', 'lampiran' => lampiranPdf()]))->assertCreated();
    $this->actingAs($this->kepsek)->post('/api/v1/pengumuman', isianPengumuman(['judul' => 'Jadwal PPDB', 'is_publik' => '1', 'publish' => '0', 'lampiran' => lampiranPdf()]))->assertCreated();
    $this->actingAs($this->kepsek)->post('/api/v1/pengumuman', isianPengumuman(['judul' => 'Lomba mewarnai', 'is_publik' => '1']))->assertCreated();
    app('auth')->forgetGuards();

    foreach (Pengumuman::query()->pluck('slug') as $slug) {
        $this->get("/api/v1/public/pengumuman/{$slug}/lampiran")->assertNotFound();
    }
});

it('menghapus file lampiran saat pengumuman dihapus', function () {
    $this->actingAs($this->kepsek)->post('/api/v1/pengumuman', isianPengumuman(['lampiran' => lampiranPdf()]))->assertCreated();
    $pengumuman = Pengumuman::query()->sole();

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/pengumuman/{$pengumuman->id}")->assertOk();

    Storage::disk('local')->assertMissing((string) $pengumuman->lampiran_path);
});
