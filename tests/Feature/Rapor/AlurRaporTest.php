<?php

use App\Enums\Hubungan;
use App\Enums\StatusKelasMurid;
use App\Enums\StatusRapor;
use App\Models\ElemenPenilaian;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Rapor;
use App\Models\RaporDetail;
use App\Models\TahunAjaran;
use App\Models\WaliMurid;
use App\Notifications\RaporDiajukanNotification;
use App\Notifications\RaporRevisiNotification;
use App\Notifications\RaporTerbitNotification;
use Database\Seeders\ElemenPenilaianSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * TK A1 (2026/2027) diampu Bu Aini (wali kelas) dan Bu Rina (pendamping); TK B1 diampu Bu Sri. Aisyah Putri
 * di TK A1, tertaut ke ayah dan ibunya. Tiga elemen penilaian dari seeder.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-12-10 13:00:00');
    Storage::fake('local');
    Notification::fake();
    $this->seed(ElemenPenilaianSeeder::class);

    $this->kepsek = buatKepalaSekolah();
    $this->tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->buAini = buatGuru();
    $this->buRina = buatGuru();
    $this->buSri = buatGuru();
    $this->kelasA1 = Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id, 'guru_pendamping_id' => $this->buRina->id]);
    Kelas::factory()->for($this->tahunAjaran)->create(['nama' => 'TK B1', 'wali_kelas_id' => $this->buSri->id]);

    $this->aisyah = Murid::factory()->create(['nama_lengkap' => 'Aisyah Putri', 'nama_panggilan' => 'Aisyah']);
    $this->kelasA1->murid()->attach($this->aisyah);
    $this->ibu = WaliMurid::factory()->create();
    $this->ayah = WaliMurid::factory()->create();
    $this->aisyah->waliMurid()->attach($this->ibu, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);
    $this->aisyah->waliMurid()->attach($this->ayah, ['hubungan' => Hubungan::Ayah, 'is_kontak_utama' => false]);
});

function buatDraftRapor(object $test): Rapor
{
    $id = $test->actingAs($test->buAini->user)->postJson('/api/v1/rapor', ['murid_id' => $test->aisyah->id, 'semester' => 1])
        ->assertCreated()->json('data.id');

    return Rapor::query()->findOrFail($id);
}

function isiLengkap(Rapor $rapor): array
{
    return [
        'tinggi_badan' => 108.5,
        'berat_badan' => 18,
        'catatan_guru' => 'Aisyah ceria dan senang membantu teman.',
        'detail' => ElemenPenilaian::query()->orderBy('urutan')->get()->map(fn (ElemenPenilaian $elemen) => [
            'elemen_penilaian_id' => $elemen->id,
            'deskripsi' => "Aisyah berkembang sesuai harapan pada elemen {$elemen->kode}.",
        ])->all(),
    ];
}

it('membuat draft rapor dengan baris kosong untuk setiap elemen aktif', function () {
    ElemenPenilaian::factory()->create(['kode' => 'LAMA', 'is_aktif' => false]);

    $this->actingAs($this->buAini->user)->postJson('/api/v1/rapor', ['murid_id' => $this->aisyah->id, 'semester' => 1])
        ->assertCreated()
        ->assertJsonPath('message', 'Draft rapor semester 1 Aisyah Putri dibuat.')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.kelas.nama', 'TK A1')
        ->assertJsonPath('data.tahun_ajaran.nama', '2026/2027')
        ->assertJsonPath('data.pembuat.id', $this->buAini->id)
        ->assertJsonCount(3, 'data.detail')
        ->assertJsonPath('data.detail.0.elemen.kode', 'NAB')
        ->assertJsonPath('data.detail.0.deskripsi', null);
});

it('menolak rapor ganda untuk murid, tahun ajaran, dan semester yang sama', function () {
    buatDraftRapor($this);

    $this->actingAs($this->buRina->user)->postJson('/api/v1/rapor', ['murid_id' => $this->aisyah->id, 'semester' => 1])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Rapor semester 1 untuk Aisyah Putri di tahun ajaran ini sudah ada.');

    $this->actingAs($this->buRina->user)->postJson('/api/v1/rapor', ['murid_id' => $this->aisyah->id, 'semester' => 2])->assertCreated();
});

it('menolak guru membuat rapor untuk murid di luar kelas yang dia ampu', function () {
    $this->actingAs($this->buSri->user)->postJson('/api/v1/rapor', ['murid_id' => $this->aisyah->id, 'semester' => 1])
        ->assertStatus(422)
        ->assertJsonPath('errors.murid_id.0', 'Murid tidak ditemukan di kelas yang Anda ampu.');
});

it('menolak rapor untuk murid yang penempatannya di kelas sudah tidak aktif', function () {
    $this->kelasA1->murid()->updateExistingPivot($this->aisyah->id, ['status' => StatusKelasMurid::Keluar]);

    $this->actingAs($this->kepsek)->postJson('/api/v1/rapor', ['murid_id' => $this->aisyah->id, 'semester' => 1])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Aisyah Putri belum punya kelas aktif di tahun ajaran ini, jadi rapornya belum bisa dibuat.');
});

it('menjalankan alur lengkap rapor sampai terbit dan memberi tahu pihak terkait', function () {
    $rapor = buatDraftRapor($this);
    $url = "/dashboard/rapor/{$rapor->id}";

    $this->actingAs($this->buAini->user)->putJson("/api/v1/rapor/{$rapor->id}", isiLengkap($rapor))
        ->assertOk()
        ->assertJsonPath('data.tinggi_badan', 108.5)
        ->assertJsonPath('data.berat_badan', 18)
        ->assertJsonPath('data.detail.1.deskripsi', 'Aisyah berkembang sesuai harapan pada elemen JD.');

    $this->actingAs($this->buAini->user)->postJson("/api/v1/rapor/{$rapor->id}/ajukan")
        ->assertOk()
        ->assertJsonPath('data.status', 'diajukan')
        ->assertJsonPath('data.diajukan_at', '2026-12-10T13:00:00+07:00');
    Notification::assertSentTo($this->kepsek, RaporDiajukanNotification::class, function ($notifikasi) use ($url) {
        $isi = $notifikasi->toDatabase($this->kepsek);

        return $isi['jenis'] === 'rapor_diajukan'
            && $isi['pesan'] === "Rapor semester 1 Aisyah Putri (TK A1) diajukan {$this->buAini->user->name} dan menunggu review Anda."
            && $isi['url'] === $url;
    });

    $this->actingAs($this->buAini->user)->putJson("/api/v1/rapor/{$rapor->id}", isiLengkap($rapor))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Rapor berstatus Diajukan tidak bisa diubah. Rapor hanya bisa diisi saat berstatus draft atau revisi.');

    $this->actingAs($this->kepsek)->postJson("/api/v1/rapor/{$rapor->id}/revisi", ['catatan' => 'Deskripsi Jati Diri masih umum.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'revisi')
        ->assertJsonPath('data.catatan_revisi', 'Deskripsi Jati Diri masih umum.');
    Notification::assertSentTo($this->buAini->user, RaporRevisiNotification::class, fn ($notifikasi) => $notifikasi->toDatabase($this->buAini->user)['pesan']
        === 'Kepala Sekolah meminta revisi rapor semester 1 Aisyah Putri (TK A1): Deskripsi Jati Diri masih umum.');

    $this->actingAs($this->buAini->user)->putJson("/api/v1/rapor/{$rapor->id}", ['detail' => [['elemen_penilaian_id' => ElemenPenilaian::query()->where('kode', 'JD')->value('id'), 'deskripsi' => 'Aisyah sudah berani bercerita di depan kelas.']]])
        ->assertOk()
        ->assertJsonPath('data.detail.1.deskripsi', 'Aisyah sudah berani bercerita di depan kelas.')
        ->assertJsonPath('data.detail.0.deskripsi', 'Aisyah berkembang sesuai harapan pada elemen NAB.');
    $this->actingAs($this->buAini->user)->postJson("/api/v1/rapor/{$rapor->id}/ajukan")->assertOk();

    Carbon::setTestNow('2026-12-18 09:00:00');
    $this->actingAs($this->kepsek)->postJson("/api/v1/rapor/{$rapor->id}/terbitkan")
        ->assertOk()
        ->assertJsonPath('message', 'Rapor Aisyah Putri diterbitkan.')
        ->assertJsonPath('data.status', 'terbit')
        ->assertJsonPath('data.terbit_at', '2026-12-18T09:00:00+07:00');

    expect($rapor->fresh()?->disetujui_oleh)->toBe($this->kepsek->id);
    Notification::assertSentTo([$this->ibu->user, $this->ayah->user], RaporTerbitNotification::class, fn ($notifikasi) => $notifikasi->toDatabase($this->ibu->user)
        === ['jenis' => 'rapor_terbit', 'judul' => 'Rapor sudah terbit', 'pesan' => 'Rapor semester 1 tahun ajaran 2026/2027 untuk Aisyah sudah terbit dan bisa diunduh.', 'url' => $url]);
    expect(Activity::query()->where('log_name', 'rapor')->pluck('event')->all())->toBe(['revisi', 'terbit']);
});

it('menolak mengajukan rapor yang deskripsi elemennya belum lengkap', function () {
    $rapor = buatDraftRapor($this);
    $data = isiLengkap($rapor);
    $data['detail'][2]['deskripsi'] = '  ';

    $this->actingAs($this->buAini->user)->putJson("/api/v1/rapor/{$rapor->id}", $data)->assertOk();

    $this->actingAs($this->buAini->user)->postJson("/api/v1/rapor/{$rapor->id}/ajukan")
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE')
        ->assertJsonPath('message', 'Lengkapi deskripsi elemen berikut sebelum mengajukan rapor: Dasar-dasar Literasi, Matematika, Sains, Teknologi, Rekayasa & Seni.');

    Notification::assertNothingSent();
});

it('hanya mengizinkan transisi status sesuai alur', function (StatusRapor $status, string $aksi) {
    $rapor = Rapor::factory()->for($this->aisyah)->for($this->kelasA1)->create(['status' => $status, 'dibuat_oleh' => $this->buAini->id]);
    $pelaku = $aksi === 'ajukan' ? $this->buAini->user : $this->kepsek;

    $this->actingAs($pelaku)->postJson("/api/v1/rapor/{$rapor->id}/{$aksi}", ['catatan' => 'Perbaiki.'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect($rapor->fresh()?->status)->toBe($status);
})->with([
    'terbitkan draft' => [StatusRapor::Draft, 'terbitkan'],
    'terbitkan revisi' => [StatusRapor::Revisi, 'terbitkan'],
    'terbitkan ulang' => [StatusRapor::Terbit, 'terbitkan'],
    'revisi draft' => [StatusRapor::Draft, 'revisi'],
    'revisi yang sudah terbit' => [StatusRapor::Terbit, 'revisi'],
    'ajukan ulang' => [StatusRapor::Diajukan, 'ajukan'],
    'ajukan yang sudah terbit' => [StatusRapor::Terbit, 'ajukan'],
]);

it('hanya guru pembuat yang bisa mengisi dan mengajukan rapor', function () {
    $rapor = buatDraftRapor($this);

    $this->actingAs($this->buRina->user)->putJson("/api/v1/rapor/{$rapor->id}", isiLengkap($rapor))->assertForbidden();
    $this->actingAs($this->buRina->user)->postJson("/api/v1/rapor/{$rapor->id}/ajukan")->assertForbidden();
    $this->actingAs($this->kepsek)->putJson("/api/v1/rapor/{$rapor->id}", isiLengkap($rapor))->assertForbidden();
    $this->actingAs($this->buSri->user)->putJson("/api/v1/rapor/{$rapor->id}", isiLengkap($rapor))->assertNotFound();
    $this->actingAs($this->ibu->user)->putJson("/api/v1/rapor/{$rapor->id}", isiLengkap($rapor))->assertForbidden();
});

it('hanya Kepala Sekolah yang bisa menerbitkan atau meminta revisi', function () {
    $rapor = Rapor::factory()->for($this->aisyah)->for($this->kelasA1)->create(['status' => StatusRapor::Diajukan, 'dibuat_oleh' => $this->buAini->id]);

    $this->actingAs($this->buAini->user)->postJson("/api/v1/rapor/{$rapor->id}/terbitkan")->assertForbidden();
    $this->actingAs($this->buAini->user)->postJson("/api/v1/rapor/{$rapor->id}/revisi", ['catatan' => 'x'])->assertForbidden();
});

it('menolak detail untuk elemen yang tidak ada di rapor', function () {
    $rapor = buatDraftRapor($this);
    $lain = ElemenPenilaian::factory()->create(['kode' => 'BARU']);

    $this->actingAs($this->buAini->user)->putJson("/api/v1/rapor/{$rapor->id}", ['detail' => [['elemen_penilaian_id' => $lain->id, 'deskripsi' => 'Isi']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['detail']);
});

it('menyimpan foto elemen, mengganti foto lama, dan menolaknya setelah rapor diajukan', function () {
    $rapor = buatDraftRapor($this);
    $detail = $rapor->detail()->orderBy('id')->firstOrFail();

    $this->actingAs($this->buAini->user)->post("/api/v1/rapor/{$rapor->id}/detail/{$detail->id}/foto", ['foto' => UploadedFile::fake()->image('karya.jpg')])->assertOk();
    $pathLama = (string) $detail->fresh()?->foto_path;
    $response = $this->actingAs($this->buAini->user)->post("/api/v1/rapor/{$rapor->id}/detail/{$detail->id}/foto", ['foto' => UploadedFile::fake()->image('karya2.jpg')])
        ->assertOk();

    $pathBaru = (string) $detail->fresh()?->foto_path;
    expect($pathBaru)->toStartWith('rapor/')->not->toBe($pathLama)
        ->and($response->json('data.detail.0.foto_url'))->toContain('/api/v1/media/');
    Storage::disk('local')->assertMissing($pathLama);
    Storage::disk('local')->assertExists($pathBaru);

    $this->actingAs($this->buAini->user)->putJson("/api/v1/rapor/{$rapor->id}", isiLengkap($rapor));
    $this->actingAs($this->buAini->user)->postJson("/api/v1/rapor/{$rapor->id}/ajukan")->assertOk();
    $this->actingAs($this->buAini->user)->post("/api/v1/rapor/{$rapor->id}/detail/{$detail->id}/foto", ['foto' => UploadedFile::fake()->image('karya3.jpg')])
        ->assertStatus(422);
    expect(Storage::disk('local')->allFiles('rapor'))->toBe([$pathBaru]);
});

it('membalas 404 untuk foto detail milik rapor lain', function () {
    $rapor = buatDraftRapor($this);
    $detailLain = RaporDetail::factory()->create();

    $this->actingAs($this->buAini->user)->post("/api/v1/rapor/{$rapor->id}/detail/{$detailLain->id}/foto", ['foto' => UploadedFile::fake()->image('a.jpg')])
        ->assertNotFound();
});
