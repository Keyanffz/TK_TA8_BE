<?php

namespace App\Support;

use App\Enums\Agama;
use App\Enums\GolonganDarah;
use App\Enums\Hubungan;
use App\Enums\HubunganWali;
use App\Enums\Imunisasi;
use App\Enums\Kewarganegaraan;
use App\Enums\Pekerjaan;
use App\Enums\PendidikanTerakhir;
use App\Enums\Penghasilan;
use App\Enums\StatusOrangTua;
use App\Enums\StatusYatimPiatu;
use App\Rules\NomorHp;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Validation\Rule;

/**
 * Isian formulir data murid resmi sekolah yang dipakai bersama oleh murid dan pendaftaran PPDB: kolom data anak,
 * aturan format (semua boleh kosong; field wajib diperiksa `KelengkapanFormulir`), dan nama field di pesan error.
 */
final class FormulirMurid
{
    /** Kunci isian orang tua di request dan nilai `peran` di tabel `orang_tua`. */
    public const PERAN = ['ayah', 'ibu', 'wali'];

    /** Kolom data anak dari formulir selain data diri lama (nama, NIK, tempat/tanggal lahir, agama, alamat). */
    public const KOLOM_ANAK = [
        'desa_kelurahan', 'kecamatan', 'kabupaten_kota', 'provinsi', 'asal_sekolah', 'kewarganegaraan',
        'jumlah_saudara_kandung', 'jumlah_saudara_tiri', 'jumlah_saudara_angkat', 'status_yatim_piatu', 'bahasa_sehari_hari',
        'jarak_rumah_km', 'golongan_darah', 'riwayat_penyakit', 'tinggi_badan', 'berat_badan', 'imunisasi',
        'imunisasi_lainnya', 'ciri_khusus', 'kontak_utama',
    ];

    /** Centang berkas fisik: waktu dan akun yang mencentang. */
    public const KOLOM_BERKAS = ['kk_diterima_at', 'kk_diterima_oleh', 'akta_diterima_at', 'akta_diterima_oleh'];

    public const CAST_ANAK = [
        'kewarganegaraan' => Kewarganegaraan::class,
        'jumlah_saudara_kandung' => 'integer',
        'jumlah_saudara_tiri' => 'integer',
        'jumlah_saudara_angkat' => 'integer',
        'status_yatim_piatu' => StatusYatimPiatu::class,
        'jarak_rumah_km' => 'float',
        'golongan_darah' => GolonganDarah::class,
        'tinggi_badan' => 'float',
        'berat_badan' => 'float',
        'imunisasi' => 'array',
        'kontak_utama' => Hubungan::class,
    ];

    /**
     * @return array<string, list<mixed>>
     */
    public static function aturanAnak(): array
    {
        $saudara = ['nullable', 'integer', 'min:0', 'max:20'];

        return [
            'desa_kelurahan' => ['nullable', 'string', 'max:100'],
            'kecamatan' => ['nullable', 'string', 'max:100'],
            'kabupaten_kota' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
            'asal_sekolah' => ['nullable', 'string', 'max:150'],
            'kewarganegaraan' => ['nullable', Rule::enum(Kewarganegaraan::class)],
            'jumlah_saudara_kandung' => $saudara,
            'jumlah_saudara_tiri' => $saudara,
            'jumlah_saudara_angkat' => $saudara,
            'status_yatim_piatu' => ['nullable', Rule::enum(StatusYatimPiatu::class)],
            'bahasa_sehari_hari' => ['nullable', 'string', 'max:50'],
            'jarak_rumah_km' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999.99'],
            'golongan_darah' => ['nullable', Rule::enum(GolonganDarah::class)],
            'riwayat_penyakit' => ['nullable', 'string', 'max:1000'],
            'tinggi_badan' => ['nullable', 'numeric', 'decimal:0,1', 'between:30,200'],
            'berat_badan' => ['nullable', 'numeric', 'decimal:0,1', 'between:2,150'],
            'imunisasi' => ['nullable', 'array'],
            'imunisasi.*' => ['distinct', Rule::enum(Imunisasi::class)],
            'imunisasi_lainnya' => ['nullable', 'string', 'max:255'],
            'ciri_khusus' => ['nullable', 'string', 'max:1000'],
            'kontak_utama' => ['nullable', Rule::enum(Hubungan::class)],
        ];
    }

    /**
     * Objek `ayah`, `ibu`, dan `wali`. Objek yang semua isiannya kosong menghapus data orang itu.
     *
     * @return array<string, list<mixed>>
     */
    public static function aturanOrangTua(): array
    {
        $aturan = [];

        foreach (self::PERAN as $peran) {
            $aturan += [
                $peran => ['nullable', 'array'],
                ...($peran === 'wali'
                    ? ['wali.hubungan' => ['nullable', Rule::enum(HubunganWali::class)], 'wali.hubungan_lainnya' => ['nullable', 'string', 'max:50']]
                    : ["{$peran}.status" => ['nullable', Rule::enum(StatusOrangTua::class)]]),
                "{$peran}.nama" => ['nullable', 'string', 'max:255'],
                "{$peran}.tempat_lahir" => ['nullable', 'string', 'max:100'],
                "{$peran}.tanggal_lahir" => ['nullable', 'date_format:Y-m-d', 'before:today'],
                "{$peran}.agama" => ['nullable', Rule::enum(Agama::class)],
                "{$peran}.kewarganegaraan" => ['nullable', Rule::enum(Kewarganegaraan::class)],
                "{$peran}.pendidikan_terakhir" => ['nullable', Rule::enum(PendidikanTerakhir::class)],
                "{$peran}.pekerjaan" => ['nullable', Rule::enum(Pekerjaan::class)],
                "{$peran}.pekerjaan_lainnya" => ['nullable', 'string', 'max:100'],
                "{$peran}.penghasilan" => ['nullable', Rule::enum(Penghasilan::class)],
                "{$peran}.alamat_sama_dengan_anak" => ['nullable', 'boolean'],
                "{$peran}.alamat_rumah" => ['nullable', 'string', 'max:500'],
                "{$peran}.alamat_kantor" => ['nullable', 'string', 'max:500'],
                "{$peran}.no_hp" => ['nullable', new NomorHp],
            ];
        }

        return $aturan;
    }

    /**
     * @return array<string, string>
     */
    public static function atribut(): array
    {
        $atribut = [
            'nama_lengkap' => 'nama lengkap',
            'nama_panggilan' => 'nama panggilan',
            'nik' => 'NIK anak',
            'anak_ke' => 'anak ke-',
            'desa_kelurahan' => 'desa/kelurahan',
            'kabupaten_kota' => 'kabupaten/kota',
            'asal_sekolah' => 'asal sekolah',
            'jumlah_saudara_kandung' => 'jumlah saudara kandung',
            'jumlah_saudara_tiri' => 'jumlah saudara tiri',
            'jumlah_saudara_angkat' => 'jumlah saudara angkat',
            'status_yatim_piatu' => 'status yatim/piatu',
            'bahasa_sehari_hari' => 'bahasa sehari-hari',
            'jarak_rumah_km' => 'jarak rumah',
            'golongan_darah' => 'golongan darah',
            'riwayat_penyakit' => 'penyakit yang pernah diderita',
            'tinggi_badan' => 'tinggi badan',
            'berat_badan' => 'berat badan',
            'imunisasi.*' => 'imunisasi',
            'imunisasi_lainnya' => 'imunisasi lainnya',
            'ciri_khusus' => 'ciri-ciri khusus',
            'kontak_utama' => 'kontak utama',
        ];
        $isian = [
            'status' => 'status', 'hubungan' => 'hubungan', 'hubungan_lainnya' => 'hubungan', 'nama' => 'nama',
            'tempat_lahir' => 'tempat lahir', 'tanggal_lahir' => 'tanggal lahir', 'agama' => 'agama',
            'kewarganegaraan' => 'kewarganegaraan', 'pendidikan_terakhir' => 'pendidikan terakhir', 'pekerjaan' => 'pekerjaan',
            'pekerjaan_lainnya' => 'pekerjaan', 'penghasilan' => 'penghasilan', 'alamat_sama_dengan_anak' => 'pilihan alamat',
            'alamat_rumah' => 'alamat rumah', 'alamat_kantor' => 'alamat kantor', 'no_hp' => 'nomor HP',
        ];

        foreach (self::PERAN as $peran) {
            foreach ($isian as $kolom => $nama) {
                $atribut["{$peran}.{$kolom}"] = "{$nama} {$peran}";
            }
        }

        return $atribut;
    }

    /**
     * Nilai isian dalam bentuk mentah: enum menjadi nilainya, tanggal menjadi `YYYY-MM-DD`.
     */
    public static function polos(mixed $nilai): mixed
    {
        return match (true) {
            $nilai instanceof BackedEnum => $nilai->value,
            $nilai instanceof DateTimeInterface => $nilai->format('Y-m-d'),
            default => $nilai,
        };
    }
}
