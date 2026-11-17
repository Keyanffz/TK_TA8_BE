<?php

namespace App\Support;

use App\Enums\Agama;
use App\Enums\GolonganDarah;
use App\Enums\Hubungan;
use App\Enums\HubunganWali;
use App\Enums\Imunisasi;
use App\Enums\JenisKelamin;
use App\Enums\Kewarganegaraan;
use App\Enums\Pekerjaan;
use App\Enums\PendidikanTerakhir;
use App\Enums\Penghasilan;
use App\Enums\StatusMurid;
use App\Enums\StatusOrangTua;
use App\Enums\StatusYatimPiatu;
use BackedEnum;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Kolom template import murid: urutan, petunjuk, kolom wajib (judulnya diberi `*`), kolom pilihan (dropdown di
 * Excel, diisi label atau kode), dan kolom yang berformat teks. Dipakai template, sheet Petunjuk, dan pembaca import.
 */
final class KolomImportMurid
{
    /** Hanya data diri lama yang wajib, supaya data yang belum lengkap tetap bisa diimpor. */
    public const WAJIB = ['nama_lengkap', 'nama_panggilan', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'agama', 'alamat', 'tanggal_masuk'];

    /**
     * Daftar pilihan di sheet Petunjuk, mulai kolom E, berurutan.
     *
     * @var array<class-string<BackedEnum>, string>
     */
    public const DAFTAR_PILIHAN = [
        JenisKelamin::class => 'Jenis kelamin',
        Agama::class => 'Agama',
        Kewarganegaraan::class => 'Kewarganegaraan',
        StatusYatimPiatu::class => 'Status yatim/piatu',
        GolonganDarah::class => 'Golongan darah',
        Imunisasi::class => 'Imunisasi',
        Hubungan::class => 'Kontak utama',
        StatusMurid::class => 'Status murid',
        StatusOrangTua::class => 'Status ayah/ibu',
        HubunganWali::class => 'Hubungan wali',
        PendidikanTerakhir::class => 'Pendidikan terakhir',
        Pekerjaan::class => 'Pekerjaan',
        Penghasilan::class => 'Penghasilan per bulan',
    ];

    public const KOLOM_PILIHAN_PERTAMA = 5;

    /**
     * Kolom berurutan beserta petunjuknya.
     *
     * @return array<string, string>
     */
    public static function petunjuk(): array
    {
        return [
            'nama_lengkap' => 'Nama lengkap murid.',
            'nama_panggilan' => 'Nama panggilan, ditulis di kartu akun wali.',
            'nisn' => '10 digit. Baris dengan NISN yang sudah terdaftar dilewati dan dilaporkan.',
            'nik' => '16 digit, lihat Kartu Keluarga. Kolom sudah berformat teks supaya digit terakhir tidak berubah.',
            'jenis_kelamin' => 'L atau P, atau pilih dari daftar.',
            'tempat_lahir' => 'Kota tempat lahir.',
            'tanggal_lahir' => 'YYYY-MM-DD, misalnya 2021-05-14 (DD/MM/YYYY juga diterima). Untuk murid aktif, ini juga password awal akun wali (DDMMYYYY) yang dibuat setelah Kepala Sekolah mengisi NIS.',
            'agama' => 'Pilih dari daftar.',
            'alamat' => 'Jalan, nomor rumah, RT/RW.',
            'desa_kelurahan' => 'Desa atau kelurahan.',
            'kecamatan' => 'Kecamatan.',
            'kabupaten_kota' => 'Kabupaten atau kota.',
            'provinsi' => 'Provinsi.',
            'kewarganegaraan' => 'WNI atau WNA.',
            'anak_ke' => 'Angka 1 sampai 20.',
            'jumlah_saudara_kandung' => 'Angka, 0 kalau tidak ada.',
            'jumlah_saudara_tiri' => 'Angka, 0 kalau tidak ada.',
            'jumlah_saudara_angkat' => 'Angka, 0 kalau tidak ada.',
            'status_yatim_piatu' => 'Pilih dari daftar. Ayah yang sudah meninggal (yatim) atau ibu yang sudah meninggal (piatu) cukup diisi namanya.',
            'asal_sekolah' => 'Kosongkan kalau belum pernah sekolah.',
            'bahasa_sehari_hari' => 'Bahasa di rumah, misalnya Jawa.',
            'jarak_rumah_km' => 'Kilometer, desimal memakai titik, misalnya 1.5.',
            'golongan_darah' => 'Pilih dari daftar.',
            'riwayat_penyakit' => 'Penyakit yang pernah diderita.',
            'tinggi_badan' => 'Sentimeter saat masuk, misalnya 105.',
            'berat_badan' => 'Kilogram saat masuk, misalnya 17.5.',
            'imunisasi' => 'Pisahkan dengan koma, misalnya BCG, Polio, DPT. Pilihan ada di daftar Imunisasi.',
            'imunisasi_lainnya' => 'Imunisasi lain di luar daftar.',
            'ciri_khusus' => 'Ciri-ciri khusus anak.',
            'catatan_khusus' => 'Catatan untuk guru, misalnya alergi makanan atau kebutuhan khusus.',
            'kontak_utama' => 'Ayah, Ibu, atau Wali. Nama dan nomor HP orang ini dipakai akun wali murid saat NIS diisi, jadi nomor HP-nya wajib ada.',
            'tanggal_masuk' => 'YYYY-MM-DD.',
            'status' => 'aktif, lulus, pindah, atau keluar; kosong berarti aktif. Murid lulus, pindah, dan keluar tidak dibuatkan akun wali dan tidak ditagih.',
            'tanggal_status' => 'Tanggal lulus, pindah, atau keluar (YYYY-MM-DD); kosong untuk murid aktif. Kosong untuk murid lulus berarti akhir tahun ajaran di tahun lulus.',
            'tahun_lulus' => 'Tahun lulus, misalnya 2019.',
            'sekolah_tujuan' => 'Hanya untuk status pindah.',
            'keterangan' => 'Keterangan status, hanya untuk murid lulus, pindah, atau keluar.',
            'kelas' => 'Hanya untuk murid aktif: nama kelas di tahun ajaran aktif, misalnya TK A1. Murid aktif tanpa kelas belum ikut tagihan SPP sampai ditempatkan.',
            ...self::petunjukOrangTua('ayah'),
            ...self::petunjukOrangTua('ibu'),
            ...self::petunjukOrangTua('wali'),
        ];
    }

    /**
     * Enum tiap kolom pilihan.
     *
     * @return array<string, class-string<BackedEnum>>
     */
    public static function pilihan(): array
    {
        $pilihan = [
            'jenis_kelamin' => JenisKelamin::class,
            'agama' => Agama::class,
            'kewarganegaraan' => Kewarganegaraan::class,
            'status_yatim_piatu' => StatusYatimPiatu::class,
            'golongan_darah' => GolonganDarah::class,
            'kontak_utama' => Hubungan::class,
            'status' => StatusMurid::class,
            'ayah_status' => StatusOrangTua::class,
            'ibu_status' => StatusOrangTua::class,
            'wali_hubungan' => HubunganWali::class,
        ];

        foreach (FormulirMurid::PERAN as $peran) {
            $pilihan += [
                "{$peran}_agama" => Agama::class,
                "{$peran}_kewarganegaraan" => Kewarganegaraan::class,
                "{$peran}_pendidikan_terakhir" => PendidikanTerakhir::class,
                "{$peran}_pekerjaan" => Pekerjaan::class,
                "{$peran}_penghasilan" => Penghasilan::class,
            ];
        }

        return $pilihan;
    }

    /**
     * Kolom yang dibuat berformat teks di template: Excel mengubah angka panjang menjadi notasi ilmiah (NIK kehilangan
     * digit terakhir), membuang nol di depan nomor HP dan NISN, dan mengikuti format tanggal lokal komputer.
     *
     * @return list<string>
     */
    public static function teks(): array
    {
        return ['nisn', 'nik', ...self::tanggal(), 'ayah_no_hp', 'ibu_no_hp', 'wali_no_hp'];
    }

    /**
     * @return list<string>
     */
    public static function tanggal(): array
    {
        return ['tanggal_lahir', 'tanggal_masuk', 'tanggal_status', 'ayah_tanggal_lahir', 'ibu_tanggal_lahir', 'wali_tanggal_lahir'];
    }

    /**
     * Judul kolom di template; kolom wajib diberi `*`. Pembaca import membuang `*` lewat slug judul kolom.
     */
    public static function judul(string $kolom): string
    {
        return in_array($kolom, self::WAJIB, true) ? "{$kolom}*" : $kolom;
    }

    /**
     * Label atau kode pilihan (tanpa beda huruf besar) menjadi kode. Nilai di luar daftar dikembalikan apa adanya
     * supaya ditolak validasi dengan pesan yang menyebut kolomnya.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function kode(string $enum, string $nilai): string
    {
        $cari = Str::lower(trim($nilai));

        foreach ($enum::cases() as $pilihan) {
            if (Str::lower((string) $pilihan->value) === $cari || (method_exists($pilihan, 'label') && Str::lower($pilihan->label()) === $cari)) {
                return (string) $pilihan->value;
            }
        }

        return $nilai;
    }

    /**
     * Rentang daftar pilihan di sheet Petunjuk, untuk rumus data validation di sheet Murid.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function rentangPilihan(string $enum): string
    {
        $huruf = Coordinate::stringFromColumnIndex(self::KOLOM_PILIHAN_PERTAMA + (int) array_search($enum, array_keys(self::DAFTAR_PILIHAN), true));

        return "'Petunjuk'!\${$huruf}\$2:\${$huruf}\$".(count($enum::cases()) + 1);
    }

    /**
     * @return array<string, string>
     */
    private static function petunjukOrangTua(string $peran): array
    {
        $petunjuk = $peran === 'wali'
            ? [
                'wali_hubungan' => 'Isi bagian wali hanya untuk anak yang tinggal dengan selain ayah/ibu. Kalau diisi, hubungan, nama, dan nomor HP wali wajib.',
                'wali_hubungan_lainnya' => 'Hubungan wali kalau memilih Lainnya.',
            ]
            : ["{$peran}_status" => 'Kandung, Tiri, atau Angkat.'];

        return [
            ...$petunjuk,
            "{$peran}_nama" => "Nama {$peran}.",
            "{$peran}_tempat_lahir" => 'Kota tempat lahir.',
            "{$peran}_tanggal_lahir" => 'YYYY-MM-DD.',
            "{$peran}_agama" => 'Pilih dari daftar.',
            "{$peran}_kewarganegaraan" => 'WNI atau WNA.',
            "{$peran}_pendidikan_terakhir" => 'Pilih dari daftar.',
            "{$peran}_pekerjaan" => 'Pilih dari daftar.',
            "{$peran}_pekerjaan_lainnya" => 'Pekerjaan kalau memilih Lainnya.',
            "{$peran}_penghasilan" => 'Pilih dari daftar.',
            "{$peran}_alamat_rumah" => 'Tulis "sama" kalau sama dengan alamat anak.',
            "{$peran}_alamat_kantor" => 'Alamat tempat kerja.',
            "{$peran}_no_hp" => 'Diawali 08, misalnya 081234567890. Kolom sudah berformat teks.',
        ];
    }
}
