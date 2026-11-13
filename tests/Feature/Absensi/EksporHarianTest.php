<?php

use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Services\RekapAbsensiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as TanggalExcel;

/**
 * Kamis, 8 Oktober 2026 pukul 16:00, setelah jam pulang tutup (15:00). Hari kerja Senin–Jumat, Jumat 2 Oktober libur.
 *
 * Bu Nur: 1 hadir dan pulang, 2 (sebelum dijadikan libur) hadir, 5 tidak hadir dari scheduler, 6 absen masuk dan pulang
 * dicatat Kepala Sekolah, 7 terlambat dan pulang lebih awal, 8 hadir dan belum absen pulang. Kepala Sekolah hanya izin
 * tanggal 5.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-08 16:00:00');
    Storage::fake('local');
    aturAbsensi(['tanggal_mulai' => '2026-09-01', 'hari_kerja' => [1, 2, 3, 4, 5], 'tanggal_libur' => ['2026-10-02']]);
    $this->kepsek = buatKepalaSekolah();
    $this->kepsek->update(['name' => 'Hj. Siti Aminah, S.Pd.']);
    $this->nur = buatGuru(atributGuru: ['jabatan' => 'Guru Kelas'])->user;
    $this->nur->update(['name' => 'Nur Aini, S.Pd.']);

    $nur = Absensi::factory()->for($this->nur);
    $dicatat = ['waktu' => null, 'latitude' => null, 'longitude' => null, 'akurasi_meter' => null, 'jarak_meter' => null, 'dikoreksi_oleh' => $this->kepsek->id, 'dikoreksi_at' => '2026-10-06 13:00:00'];
    $nur->create(['tanggal' => '2026-10-01', 'waktu' => '2026-10-01 06:55:00']);
    $nur->pulang()->create(['tanggal' => '2026-10-01', 'waktu' => '2026-10-01 13:05:00']);
    $nur->create(['tanggal' => '2026-10-02', 'waktu' => '2026-10-02 06:50:00']);
    $nur->tidakHadir()->create(['tanggal' => '2026-10-05']);
    $nur->create(['tanggal' => '2026-10-06', ...$dicatat, 'catatan_koreksi' => 'Lupa absen, ikut rapat KKG.']);
    $nur->pulang()->create(['tanggal' => '2026-10-06', ...$dicatat, 'catatan_koreksi' => 'Lupa absen pulang.']);
    $nur->create(['tanggal' => '2026-10-07', 'waktu' => '2026-10-07 07:40:00', 'status' => StatusAbsensi::Terlambat]);
    $nur->pulang()->create(['tanggal' => '2026-10-07', 'waktu' => '2026-10-07 10:15:00', 'pulang_awal' => true, 'keterangan' => 'Menjemput anak yang demam.']);
    $nur->create(['tanggal' => '2026-10-08', 'waktu' => '2026-10-08 07:00:00']);
    Absensi::factory()->for($this->kepsek)->create([
        'tanggal' => '2026-10-05', 'waktu' => '2026-10-05 06:40:00', 'status' => StatusAbsensi::Izin, 'keterangan' => 'Mengantar ibu ke rumah sakit.',
        'latitude' => null, 'longitude' => null, 'akurasi_meter' => null, 'jarak_meter' => null,
    ]);
});

const KEPSEK = ['Hj. Siti Aminah, S.Pd.', 'Kepala Sekolah'];

const NUR = ['Nur Aini, S.Pd.', 'Guru Kelas'];

it('menulis sheet Harian dengan jam masuk dan jam pulang tiap peserta per hari kerja', function () {
    $respons = $this->actingAs($this->kepsek)->get('/api/v1/absensi/rekap/export?bulan=2026-10')
        ->assertOk()
        ->assertDownload('rekap-absensi-2026-10.xlsx');
    $file = IOFactory::load($respons->baseResponse->getFile()->getPathname());
    $sheet = $file->getSheetByNameOrThrow('Harian');

    $harian = array_map(fn (array $baris): array => array_map(fn (mixed $sel): mixed => $sel ?? '', $baris), $sheet->toArray());
    expect($file->getSheetNames())->toBe(['Rekap Absensi', 'Harian'])
        ->and(array_slice($harian, 0, 14))->toBe([
            ['Tanggal', 'Hari', 'Nama', 'Jabatan', 'Jam Masuk', 'Jam Pulang', 'Status', 'Keterangan'],
            ['01/10/2026', 'Kamis', ...KEPSEK, '-', '-', '-', ''],
            ['01/10/2026', 'Kamis', ...NUR, '06:55', '13:05', 'Hadir', ''],
            ['02/10/2026', 'Jumat', ...NUR, '06:50', '-', 'Hadir', 'Tanggal libur'],
            ['05/10/2026', 'Senin', ...KEPSEK, '06:40', '-', 'Izin', 'Mengantar ibu ke rumah sakit.'],
            ['05/10/2026', 'Senin', ...NUR, '-', '-', 'Tidak Hadir', ''],
            ['06/10/2026', 'Selasa', ...KEPSEK, '-', '-', '-', ''],
            ['06/10/2026', 'Selasa', ...NUR, 'Tanpa jam', 'Tanpa jam', 'Hadir', 'Catatan koreksi: Lupa absen, ikut rapat KKG.; Catatan absen pulang: Lupa absen pulang.'],
            ['07/10/2026', 'Rabu', ...KEPSEK, '-', '-', '-', ''],
            ['07/10/2026', 'Rabu', ...NUR, '07:40', '10:15', 'Terlambat', 'Pulang lebih awal: Menjemput anak yang demam.'],
            ['08/10/2026', 'Kamis', ...KEPSEK, '-', '-', '-', ''],
            ['08/10/2026', 'Kamis', ...NUR, '07:00', '-', 'Hadir', ''],
            ['09/10/2026', 'Jumat', ...KEPSEK, '-', '-', '-', ''],
            ['09/10/2026', 'Jumat', ...NUR, '-', '-', '-', ''],
        ])
        // 21 hari kerja Oktober selain libur 2 Oktober, dua peserta, ditambah baris 2 Oktober Bu Nur dan judul kolom.
        ->and($harian)->toHaveCount(44)
        ->and(end($harian))->toBe(['30/10/2026', 'Jumat', ...NUR, '-', '-', '-', ''])
        ->and(array_column($harian, 0))->not->toContain('03/10/2026', '04/10/2026')
        ->and(TanggalExcel::isDateTime($sheet->getCell('A2')))->toBeTrue();
});

it('tetap menulis angka 0 di sheet ringkasan', function () {
    $respons = $this->actingAs($this->kepsek)->get('/api/v1/absensi/rekap/export?bulan=2026-10')->assertOk();

    $rekap = IOFactory::load($respons->baseResponse->getFile()->getPathname())->getSheetByNameOrThrow('Rekap Absensi')->toArray(formatData: false);
    expect($rekap)->toBe([
        ['Nama', 'Jabatan', 'Hadir', 'Terlambat', 'Izin', 'Sakit', 'Tidak Hadir', 'Tidak Absen Pulang'],
        [...KEPSEK, 0, 0, 1, 0, 0, 0],
        [...NUR, 4, 1, 0, 0, 1, 2],
    ]);
});

it('menulis hari kerja bulan yang belum datang dengan tanda -', function () {
    $respons = $this->actingAs($this->kepsek)->get('/api/v1/absensi/rekap/export?bulan=2026-11')->assertOk();

    $harian = IOFactory::load($respons->baseResponse->getFile()->getPathname())->getSheetByNameOrThrow('Harian')->toArray();
    expect($harian)->toHaveCount(1 + 21 * 2)
        ->and($harian[1])->toBe(['02/11/2026', 'Senin', ...KEPSEK, '-', '-', '-', null]);
});

it('menyebut sebab tanggal yang punya absensi tetapi bukan hari kerja atau sebelum tanggal mulai', function () {
    Absensi::factory()->for($this->kepsek)->create(['tanggal' => '2026-10-03', 'waktu' => '2026-10-03 07:00:00']);
    aturAbsensi(['tanggal_mulai' => '2026-10-02']);

    $harian = collect(app(RekapAbsensiService::class)->harianPerPeserta(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))
        ->mapWithKeys(fn (array $peserta): array => [$peserta['user']['nama'] => collect($peserta['hari'])->keyBy(fn (array $hari): string => $hari['tanggal']->toDateString())]);

    expect($harian[KEPSEK[0]]['2026-10-03']['keterangan'])->toBe('Bukan hari kerja')
        ->and($harian[NUR[0]]['2026-10-01']['keterangan'])->toBe('Sebelum tanggal mulai absensi')
        ->and($harian[KEPSEK[0]])->not->toHaveKey('2026-10-01');
});

it('menambahkan bagian harian per peserta di PDF, tiap peserta di halaman baru', function () {
    $respons = $this->actingAs($this->kepsek)->get('/api/v1/absensi/rekap/export?bulan=2026-10&format=pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    expect(preg_match_all('#/Type\s*/Page\b#', (string) $respons->getContent()))->toBe(3);

    $this->view('pdf.rekap-absensi', app(RekapAbsensiService::class)->isiPdf(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))
        ->assertSeeTextInOrder([
            'REKAP ABSENSI GURU DAN KEPALA SEKOLAH', 'Hj. Siti Aminah, S.Pd.', 'Nur Aini, S.Pd.',
            'Hj. Siti Aminah, S.Pd.', 'Kepala Sekolah · Absensi harian Oktober 2026',
            'Senin, 5 Oktober 2026', '06:40', '-', 'Izin', 'Mengantar ibu ke rumah sakit.',
            'Nur Aini, S.Pd.', 'Guru Kelas · Absensi harian Oktober 2026',
            'Tanggal', 'Jam Masuk', 'Jam Pulang', 'Status', 'Keterangan',
            'Kamis, 1 Oktober 2026', '06:55', '13:05', 'Hadir',
            'Jumat, 2 Oktober 2026', '06:50', '-', 'Hadir', 'Tanggal libur',
            'Senin, 5 Oktober 2026', '-', '-', 'Tidak Hadir',
            'Selasa, 6 Oktober 2026', 'Tanpa jam', 'Tanpa jam', 'Hadir', 'Catatan koreksi: Lupa absen, ikut rapat KKG.',
            'Rabu, 7 Oktober 2026', '07:40', '10:15', 'Terlambat', 'Pulang lebih awal: Menjemput anak yang demam.',
            'Kamis, 8 Oktober 2026', '07:00', '-', 'Hadir',
            'Jumat, 9 Oktober 2026', '-', '-', '-',
        ]);
});
