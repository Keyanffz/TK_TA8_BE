<?php

use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Exceptions\BusinessRuleException;
use App\Models\Kelas;
use App\Models\KelasMurid;
use App\Models\Murid;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\ImportMuridService;
use App\Services\KelasService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as TanggalExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;

/**
 * Tahun ajaran aktif 2026/2027 dengan kelas TK A1 (kapasitas 20). Tahun ajaran 2018/2019 tercatat (selesai 22 Juni
 * 2019) untuk tanggal lulus bawaan alumni lama.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-05 09:00:00');

    $this->kepsek = buatKepalaSekolah();
    $tahunAjaran = TahunAjaran::factory()->aktif()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-25']);
    TahunAjaran::factory()->create(['nama' => '2018/2019', 'tanggal_mulai' => '2018-07-16', 'tanggal_selesai' => '2019-06-22']);
    $this->kelasA1 = Kelas::factory()->for($tahunAjaran)->create(['nama' => 'TK A1', 'kapasitas' => 20]);
});

/**
 * @param  array<string, string|int|float|null>  $timpa
 * @return array<string, string|int|float|null>
 */
function barisMurid(array $timpa = []): array
{
    return [
        'nama_lengkap' => 'Raka Aditya Pratama',
        'nama_panggilan' => 'Raka',
        'nisn' => '0211234501',
        'jenis_kelamin' => 'L',
        'tempat_lahir' => 'Semarang',
        'tanggal_lahir' => '2021-05-14',
        'agama' => 'Islam',
        'alamat' => 'Jl. Pandanaran No. 5, Semarang',
        'tanggal_masuk' => '2026-07-13',
        ...$timpa,
    ];
}

/**
 * @param  list<array<string, string|int|float|null>>  $baris
 * @param  list<string>|null  $judul
 */
function fileXlsx(array $baris, ?array $judul = null): UploadedFile
{
    $judul ??= array_keys(ImportMuridService::KOLOM);
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([
        $judul,
        ...array_map(fn (array $isi): array => array_map(fn (string $kolom) => $isi[$kolom] ?? null, $judul), $baris),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'import-murid');
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'data-murid.xlsx', null, null, true);
}

it('menyediakan template Excel dengan sheet Murid dan Petunjuk', function () {
    $respons = $this->actingAs($this->kepsek)->get('/api/v1/murid/import/template')->assertOk();
    $spreadsheet = IOFactory::load($respons->getFile()->getPathname());

    expect($respons->headers->get('content-disposition'))->toContain('template-import-murid.xlsx')
        ->and($spreadsheet->getSheetNames())->toBe(['Murid', 'Petunjuk'])
        ->and($spreadsheet->getSheet(0)->toArray()[0])->toBe(array_keys(ImportMuridService::KOLOM))
        ->and($spreadsheet->getSheet(0)->getStyle('C2')->getNumberFormat()->getFormatCode())->toBe('@')
        ->and($spreadsheet->getSheet(1)->getHighestRow())->toBe(count(ImportMuridService::KOLOM) + 1);
});

it('memeriksa setiap baris di pratinjau tanpa menyimpan apa pun', function () {
    Murid::factory()->create(['nama_lengkap' => 'Nadia Salsabila', 'nisn' => '0211234599']);

    $respons = $this->actingAs($this->kepsek)->post('/api/v1/murid/import/pratinjau', ['file' => fileXlsx([
        barisMurid(['kelas' => 'tk a1']),
        barisMurid(['nisn' => '0211234502', 'nama_lengkap' => null]),
        barisMurid(['nisn' => '0211234503', 'tanggal_lahir' => '14 Mei 2021']),
        barisMurid(['nisn' => '0211234599']),
        barisMurid(['nama_lengkap' => 'Raka Kembar']),
        barisMurid(['nisn' => null, 'status' => 'lulus', 'tahun_lulus' => 2019, 'tanggal_masuk' => '2017-07-17']),
        barisMurid(['nisn' => '0211234504', 'status' => 'lulus']),
        barisMurid(['nisn' => '0211234505', 'kelas' => 'TK Z9']),
        barisMurid(['nisn' => '0211234506', 'keterangan' => 'Murid baru']),
    ])])->assertOk();

    expect($respons->json('data.ringkasan'))->toBe(['total' => 9, 'valid' => 2, 'invalid' => 7])
        ->and($respons->json('data.baris.*.nomor'))->toBe([2, 3, 4, 5, 6, 7, 8, 9, 10])
        ->and($respons->json('data.baris.*.valid'))->toBe([true, false, false, false, false, true, false, false, false])
        ->and($respons->json('data.baris.0.data.status'))->toBe('aktif')
        ->and($respons->json('data.baris.1.alasan'))->toBe(['Nama lengkap wajib diisi.'])
        ->and($respons->json('data.baris.2.alasan'))->toBe(['Tanggal lahir harus berformat YYYY-MM-DD, misalnya 2021-05-14.'])
        ->and($respons->json('data.baris.3.alasan'))->toBe(['NISN 0211234599 sudah dipakai Nadia Salsabila.'])
        ->and($respons->json('data.baris.4.alasan'))->toBe(['NISN 0211234501 sama dengan baris 2.'])
        ->and($respons->json('data.baris.6.alasan'))->toBe(['Tahun lulus wajib diisi untuk murid yang lulus.'])
        ->and($respons->json('data.baris.7.alasan'))->toBe(['Kelas TK Z9 tidak ada di tahun ajaran aktif.'])
        ->and($respons->json('data.baris.8.alasan'))->toBe(['Keterangan hanya diisi untuk murid lulus, pindah, atau keluar.'])
        ->and(Murid::query()->count())->toBe(1);
});

it('menyimpan baris valid, membuatkan akun wali untuk murid aktif, dan mengembalikan baris gagal', function () {
    $respons = $this->actingAs($this->kepsek)->post('/api/v1/murid/import', ['file' => fileXlsx([
        barisMurid(['kelas' => 'TK A1']),
        barisMurid(['nisn' => null, 'nama_lengkap' => 'Bagas Wicaksono', 'status' => 'lulus', 'tahun_lulus' => 2019, 'tanggal_masuk' => '2017-07-17']),
        barisMurid(['nisn' => null, 'nama_lengkap' => 'Citra Maharani', 'status' => 'lulus', 'tahun_lulus' => 2015, 'tanggal_masuk' => '2013-07-15']),
        barisMurid(['nisn' => null, 'nama_lengkap' => 'Dafa Ramadhan', 'status' => 'pindah', 'sekolah_tujuan' => 'TK Pertiwi', 'tanggal_masuk' => '2020-07-13']),
        barisMurid(['nisn' => '0211234509', 'jenis_kelamin' => 'X']),
    ])])
        ->assertOk()
        ->assertJsonPath('message', '4 murid diimpor, 1 baris gagal.')
        ->assertJsonPath('data.dibuat', 4)
        ->assertJsonPath('data.akun_wali_dibuat', 1)
        ->assertJsonPath('data.gagal.0.nomor', 6)
        ->assertJsonPath('data.gagal.0.alasan', ['Jenis kelamin diisi L atau P.']);

    $raka = Murid::query()->where('nisn', '0211234501')->sole();
    $akun = User::query()->where('username', $raka->nis)->sole();
    $bagas = Murid::query()->where('nama_lengkap', 'Bagas Wicaksono')->sole();

    expect($raka->nis)->toBe('TA20260001')
        ->and($akun->wajib_ganti_password)->toBeTrue()
        ->and(Hash::check('14052021', (string) $akun->password))->toBeTrue()
        ->and(KelasMurid::query()->where('murid_id', $raka->id)->sole()->status)->toBe(StatusKelasMurid::Aktif)
        ->and($bagas->nis)->toBe('TA20170001')
        ->and($bagas->status)->toBe(StatusMurid::Lulus)
        ->and($bagas->tanggal_keluar?->toDateString())->toBe('2019-06-22')
        ->and(Murid::query()->where('nama_lengkap', 'Citra Maharani')->sole()->tanggal_keluar?->toDateString())->toBe('2015-06-30')
        ->and(Murid::query()->where('nama_lengkap', 'Dafa Ramadhan')->sole()->tanggal_keluar)->toBeNull()
        ->and(User::query()->where('role', 'wali_murid')->count())->toBe(1)
        ->and(Activity::query()->where('log_name', 'murid')->where('event', 'diimpor')->sole()->properties->all())
        ->toBe(['nama_file' => 'data-murid.xlsx', 'dibuat' => 4, 'akun_wali_dibuat' => 1, 'gagal' => 1]);
});

it('membaca tanggal berformat Excel dan CSV berpemisah titik koma', function () {
    $this->actingAs($this->kepsek)->post('/api/v1/murid/import/pratinjau', ['file' => fileXlsx([
        barisMurid(['tanggal_lahir' => TanggalExcel::stringToExcel('2021-05-14'), 'nisn' => 211234501.0]),
    ])])
        ->assertOk()
        ->assertJsonPath('data.baris.0.data.tanggal_lahir', '2021-05-14')
        ->assertJsonPath('data.baris.0.data.nisn', '211234501')
        ->assertJsonPath('data.baris.0.alasan', ['NISN harus terdiri dari 10 angka.']);

    $csv = "nama_lengkap;nama_panggilan;jenis_kelamin;tempat_lahir;tanggal_lahir;agama;alamat;tanggal_masuk;status;tahun_lulus;alasan\n"
        ."Eka Saputri;Eka;P;Semarang;03/02/2016;Islam;Jl. Sriwijaya 10, Semarang;15/07/2019;lulus;2021;NISN kosong\n";

    $this->post('/api/v1/murid/import', ['file' => UploadedFile::fake()->createWithContent('alumni.csv', $csv)])
        ->assertOk()
        ->assertJsonPath('data.dibuat', 1);

    $eka = Murid::query()->where('nama_lengkap', 'Eka Saputri')->sole();
    expect($eka->tanggal_lahir->toDateString())->toBe('2016-02-03')
        ->and($eka->tahun_lulus)->toBe(2021)
        ->and($eka->tanggal_keluar?->toDateString())->toBe('2021-06-30');
});

it('menolak baris tanpa NISN yang nama dan tanggal lahirnya sudah terdaftar', function () {
    Murid::factory()->create(['nama_lengkap' => 'Raka Aditya Pratama', 'tanggal_lahir' => '2021-05-14']);

    $this->actingAs($this->kepsek)->post('/api/v1/murid/import/pratinjau', ['file' => fileXlsx([barisMurid(['nisn' => null])])])
        ->assertOk()
        ->assertJsonPath('data.baris.0.alasan', ['Murid bernama Raka Aditya Pratama dengan tanggal lahir yang sama sudah terdaftar. Isi NISN kalau ini murid yang berbeda.']);
});

it('menolak baris yang melebihi sisa kapasitas kelas', function () {
    $this->kelasA1->update(['kapasitas' => 1]);

    $this->actingAs($this->kepsek)->post('/api/v1/murid/import/pratinjau', ['file' => fileXlsx([
        barisMurid(['kelas' => 'TK A1']),
        barisMurid(['nisn' => '0211234502', 'nama_lengkap' => 'Salma Azzahra', 'kelas' => 'TK A1']),
    ])])
        ->assertOk()
        ->assertJsonPath('data.baris.0.valid', true)
        ->assertJsonPath('data.baris.1.alasan', ['Kelas TK A1 sudah penuh (kapasitas 1).']);
});

it('membatalkan seluruh import kalau penyimpanan gagal di tengah transaksi', function () {
    $this->mock(KelasService::class)->shouldReceive('tempatkanMurid')->andThrow(new BusinessRuleException('Kelas TK A1 hanya punya sisa 0 tempat.'));

    $this->actingAs($this->kepsek)->post('/api/v1/murid/import', ['file' => fileXlsx([
        barisMurid(),
        barisMurid(['nisn' => '0211234502', 'nama_lengkap' => 'Salma Azzahra', 'kelas' => 'TK A1']),
    ])])
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUSINESS_RULE');

    expect(Murid::query()->count())->toBe(0)
        ->and(User::query()->where('role', 'wali_murid')->count())->toBe(0);
});

it('mengimpor murid aktif lebih dari satu kelompok dan mengembalikan batas waktu PHP seperti semula', function () {
    $baris = array_map(fn (int $i): array => barisMurid([
        'nisn' => sprintf('03%08d', $i),
        'nama_lengkap' => "Murid Uji Import {$i}",
    ]), range(1, 60));

    $this->actingAs($this->kepsek)->post('/api/v1/murid/import', ['file' => fileXlsx($baris)])
        ->assertOk()
        ->assertJsonPath('data.dibuat', 60)
        ->assertJsonPath('data.akun_wali_dibuat', 60);

    expect(ini_get('max_execution_time'))->toBe('0');
});

it('menolak file tanpa kolom wajib, tanpa data, atau lebih dari 500 baris', function (Closure $file, string $pesan) {
    $this->actingAs($this->kepsek)->post('/api/v1/murid/import/pratinjau', ['file' => $file()])
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', $pesan);
})->with([
    'kolom wajib hilang' => [
        fn () => fileXlsx([barisMurid()], ['nama_lengkap', 'nama_panggilan', 'jenis_kelamin']),
        'Kolom wajib tidak ada di file: tempat_lahir, tanggal_lahir, agama, alamat, tanggal_masuk. Pakai template dari tombol Unduh Template.',
    ],
    'hanya judul kolom' => [fn () => fileXlsx([]), 'File tidak berisi data murid. Isi data mulai baris kedua, di bawah judul kolom.'],
    'lebih dari 500 baris' => [
        fn () => fileXlsx(array_fill(0, 501, barisMurid())),
        'File berisi 501 baris, sedangkan satu import maksimal 500 baris. Bagi menjadi beberapa file.',
    ],
    'bukan Excel atau CSV' => [fn () => UploadedFile::fake()->create('murid.pdf', 10, 'application/pdf'), 'File harus berformat .xlsx atau .csv.'],
]);
