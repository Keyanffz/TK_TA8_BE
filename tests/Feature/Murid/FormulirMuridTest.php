<?php

use App\Enums\Hubungan;
use App\Enums\StatusMurid;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\OrangTua;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaliMurid;
use Spatie\Activitylog\Models\Activity;

/**
 * Formulir data murid resmi sekolah lewat tambah dan ubah murid oleh staf. Bu Aini wali kelas TK A1 tanpa izin
 * data murid; Pak Dodi guru tata usaha dengan izin lihat data murid.
 */
beforeEach(function () {
    $this->kepsek = buatKepalaSekolah();
    $aktif = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027']);
    $this->buAini = buatGuru();
    $this->kelasA1 = Kelas::factory()->for($aktif)->create(['nama' => 'TK A1', 'wali_kelas_id' => $this->buAini->id]);
    $this->pakDodi = buatGuru();
    $this->pakDodi->izin()->create(['modul' => 'data_murid', 'level' => 'lihat']);
});

function orangTuaMurid(array $timpa = []): array
{
    return [
        'status' => 'kandung',
        'nama' => 'Bambang Wicaksono',
        'tempat_lahir' => 'Kendal',
        'tanggal_lahir' => '1989-11-02',
        'agama' => 'Islam',
        'kewarganegaraan' => 'wni',
        'pendidikan_terakhir' => 'slta',
        'pekerjaan' => 'karyawan_swasta',
        'penghasilan' => '2jt_5jt',
        'alamat_sama_dengan_anak' => true,
        'no_hp' => '081325557710',
        ...$timpa,
    ];
}

function formulirMurid(array $timpa = []): array
{
    return [
        'nama_lengkap' => 'Fatimah Azzahra',
        'nama_panggilan' => 'Fatim',
        'jenis_kelamin' => 'P',
        'tempat_lahir' => 'Semarang',
        'tanggal_lahir' => '2022-08-09',
        'agama' => 'Islam',
        'alamat' => 'Jl. Tlogosari Raya No. 40, RT 02/RW 07',
        'desa_kelurahan' => 'Tlogosari Kulon',
        'kecamatan' => 'Pedurungan',
        'kabupaten_kota' => 'Kota Semarang',
        'provinsi' => 'Jawa Tengah',
        'nik' => '3374014908220002',
        'kewarganegaraan' => 'wni',
        'anak_ke' => 1,
        'status_yatim_piatu' => 'tidak',
        'kontak_utama' => 'ayah',
        'tanggal_masuk' => '2026-07-13',
        'ayah' => orangTuaMurid(),
        'ibu' => orangTuaMurid(['nama' => 'Dewi Lestari', 'pekerjaan' => 'mengurus_rumah_tangga', 'no_hp' => null]),
        ...$timpa,
    ];
}

it('menyimpan formulir lengkap dari staf beserta ayah dan ibu, dan menandai murid lengkap', function () {
    $id = $this->actingAs($this->kepsek)->postJson('/api/v1/murid', formulirMurid())
        ->assertCreated()
        ->assertJsonPath('data.data_lengkap', true)
        ->assertJsonPath('data.data_kurang', [])
        ->assertJsonPath('data.kecamatan', 'Pedurungan')
        ->assertJsonPath('data.kontak_utama', 'ayah')
        ->assertJsonPath('data.orang_tua.ayah.nama', 'Bambang Wicaksono')
        ->assertJsonPath('data.orang_tua.ibu.pekerjaan', 'mengurus_rumah_tangga')
        ->assertJsonPath('data.orang_tua.wali', null)
        ->assertJsonPath('data.berkas', ['kartu_keluarga' => null, 'akta_kelahiran' => null])
        ->json('data.id');

    expect(OrangTua::query()->where('murid_id', $id)->pluck('peran')->map->value->sort()->values()->all())->toBe(['ayah', 'ibu']);
});

it('membolehkan staf menyimpan formulir yang belum lengkap dan menyebut field yang masih kosong', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/murid', formulirMurid(['nik' => null, 'kecamatan' => null, 'ibu' => null]))
        ->assertCreated()
        ->assertJsonPath('data.data_lengkap', false)
        ->assertJsonPath('data.data_kurang', ['nik', 'kecamatan', 'ibu.status', 'ibu.nama', 'ibu.tempat_lahir', 'ibu.tanggal_lahir', 'ibu.agama', 'ibu.kewarganegaraan', 'ibu.pendidikan_terakhir', 'ibu.pekerjaan', 'ibu.alamat_rumah']);
});

it('cukup meminta nama untuk ayah yang sudah meninggal', function () {
    $this->actingAs($this->kepsek)->postJson('/api/v1/murid', formulirMurid([
        'status_yatim_piatu' => 'yatim',
        'kontak_utama' => 'ibu',
        'ayah' => ['nama' => 'Almarhum Hadi Santoso'],
        'ibu' => orangTuaMurid(['nama' => 'Dewi Lestari', 'no_hp' => '081325557711']),
    ]))->assertCreated()->assertJsonPath('data.data_kurang', []);
});

it('menolak kontak utama yang sudah meninggal atau belum punya nomor HP, juga untuk staf', function (array $timpa, string $field) {
    $this->actingAs($this->kepsek)->postJson('/api/v1/murid', formulirMurid($timpa))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'ayah sudah meninggal' => [['status_yatim_piatu' => 'yatim'], 'kontak_utama'],
    'ibu tanpa nomor HP' => [['kontak_utama' => 'ibu'], 'ibu.no_hp'],
    'wali belum diisi' => [['kontak_utama' => 'wali'], 'kontak_utama'],
]);

it('mengganti isian orang tua saat murid diubah dan menghapus orang tua yang semua isiannya kosong', function () {
    $id = $this->actingAs($this->kepsek)->postJson('/api/v1/murid', formulirMurid([
        'wali' => ['hubungan' => 'nenek', 'nama' => 'Sumiyati', 'no_hp' => '081325557799'],
    ]))->assertCreated()->json('data.id');

    $this->actingAs($this->kepsek)->putJson("/api/v1/murid/{$id}", formulirMurid([
        'ayah' => orangTuaMurid(['pekerjaan' => 'lainnya', 'pekerjaan_lainnya' => 'Pengemudi ojek daring']),
        'wali' => ['hubungan' => null, 'nama' => null, 'no_hp' => null],
    ]))
        ->assertOk()
        ->assertJsonPath('data.orang_tua.ayah.pekerjaan_lainnya', 'Pengemudi ojek daring')
        ->assertJsonPath('data.orang_tua.wali', null);
});

it('menolak NIK yang sudah dipakai murid lain dan membebaskannya lagi saat murid itu dihapus', function () {
    $lama = Murid::factory()->create(['nik' => '3374014908220002']);

    $this->actingAs($this->kepsek)->postJson('/api/v1/murid', formulirMurid())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nik' => 'NIK anak sudah digunakan.']);

    $this->actingAs($this->kepsek)->deleteJson("/api/v1/murid/{$lama->id}")->assertOk();
    expect(Murid::withTrashed()->findOrFail($lama->id)->nik)->toBeNull();

    $this->actingAs($this->kepsek)->postJson('/api/v1/murid', formulirMurid())->assertCreated();
});

it('tidak mengirim NIK di daftar murid dan hanya mengirimnya di detail ke Kepala Sekolah, izin data murid, dan wali anak itu', function () {
    $murid = Murid::factory()->create(['nik' => '3374014908220002']);
    $this->kelasA1->murid()->attach($murid);
    $wali = WaliMurid::factory()->create();
    $murid->waliMurid()->attach($wali, ['hubungan' => Hubungan::Ibu, 'is_kontak_utama' => true]);

    $this->actingAs($this->kepsek)->getJson('/api/v1/murid')->assertOk()->assertJsonMissingPath('data.0.nik');
    $this->actingAs($this->buAini->user)->getJson("/api/v1/murid/{$murid->id}")->assertOk()->assertJsonMissingPath('data.nik');

    foreach ([$this->kepsek, $this->pakDodi->user, $wali->user] as $pengguna) {
        $this->actingAs($pengguna)->getJson("/api/v1/murid/{$murid->id}")->assertOk()->assertJsonPath('data.nik', '3374014908220002');
    }
});

it('menyaring murid yang datanya belum lengkap dan menghitung murid aktif di Perlu Tindakan', function () {
    $lengkap = Murid::factory()->lengkap()->create();
    $belum = Murid::factory()->create();
    Murid::factory()->create(['status' => StatusMurid::Lulus]);

    expect($lengkap->fresh()?->data_lengkap)->toBeTrue();

    $this->actingAs($this->kepsek)->getJson('/api/v1/murid?filter[data_lengkap]=false&filter[status]=aktif')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$belum->id])
        ->assertJsonPath('data.0.data_lengkap', false);
    $this->actingAs($this->kepsek)->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.tertunda.murid_data_belum_lengkap', 1);
});

it('mencatat siapa dan kapan berkas murid dicentang, untuk pemegang izin kelola data murid saja', function () {
    $murid = Murid::factory()->create();

    $this->actingAs($this->pakDodi->user)->patchJson("/api/v1/murid/{$murid->id}/berkas", ['kartu_keluarga' => true])->assertForbidden();
    $this->pakDodi->izin()->update(['level' => 'kelola']);

    $this->actingAs($this->pakDodi->user->fresh() ?? throw new LogicException('Akun guru hilang.'))->patchJson("/api/v1/murid/{$murid->id}/berkas", ['kartu_keluarga' => true])
        ->assertOk()
        ->assertJsonPath('data.berkas.kartu_keluarga.diterima_oleh', ['id' => $this->pakDodi->user_id, 'nama' => $this->pakDodi->user->name])
        ->assertJsonPath('data.berkas.akta_kelahiran', null);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$murid->id}/berkas", ['kartu_keluarga' => false, 'akta_kelahiran' => true])
        ->assertOk()
        ->assertJsonPath('data.berkas.kartu_keluarga', null)
        ->assertJsonPath('data.berkas.akta_kelahiran.diterima_oleh.id', $this->kepsek->id);

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$murid->id}/berkas", [])->assertStatus(422);
    expect(Activity::query()->where('event', 'berkas_diubah')->count())->toBe(2);
});

it('membuat akun wali dari nama, hubungan, dan nomor HP kontak utama saat NIS murid diisi', function () {
    $id = $this->actingAs($this->kepsek)->postJson('/api/v1/murid', formulirMurid())->assertCreated()->json('data.id');

    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$id}/nis", ['nis' => '2026101'])->assertOk();

    $akun = User::query()->where('username', '2026101')->sole();
    expect($akun->name)->toBe('Bambang Wicaksono')
        ->and($akun->no_hp)->toBe('081325557710')
        ->and(Murid::query()->findOrFail($id)->waliMurid()->sole()->pivot->hubungan)->toBe(Hubungan::Ayah);
});

it('tidak menyimpan NIK anak di log aktivitas', function () {
    $id = $this->actingAs($this->kepsek)->postJson('/api/v1/murid', formulirMurid())->assertCreated()->json('data.id');
    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$id}/berkas", ['akta_kelahiran' => true])->assertOk();
    $this->actingAs($this->kepsek)->patchJson("/api/v1/murid/{$id}/nis", ['nis' => '2026102'])->assertOk();

    expect(Activity::query()->get()->map(fn (Activity $log): string => $log->description.json_encode($log->properties))->implode(' '))
        ->not->toContain('3374014908220002');
});
