<?php

namespace App\Support;

use App\Enums\Hubungan;
use App\Enums\HubunganWali;
use App\Enums\Pekerjaan;
use App\Enums\StatusYatimPiatu;
use App\Models\Murid;
use App\Models\OrangTua;

/**
 * Field wajib formulir data murid. Satu sumber untuk penanda "Data belum lengkap" murid, validasi PPDB online
 * (wajib lengkap), dan aturan kontak utama yang juga berlaku untuk isian staf. Kunci hasil sama dengan nama field
 * request (`nik`, `ayah.pekerjaan`), supaya pesan jatuh ke isian yang benar.
 *
 * @phpstan-type Isian array<string, mixed>
 * @phpstan-type IsianOrangTua array<string, Isian|null>
 */
final class KelengkapanFormulir
{
    private const WAJIB_ANAK = [
        'nama_lengkap' => 'Nama lengkap',
        'nama_panggilan' => 'Nama panggilan',
        'nik' => 'NIK anak',
        'jenis_kelamin' => 'Jenis kelamin',
        'tempat_lahir' => 'Tempat lahir',
        'tanggal_lahir' => 'Tanggal lahir',
        'alamat' => 'Alamat (jalan, RT/RW)',
        'desa_kelurahan' => 'Desa/kelurahan',
        'kecamatan' => 'Kecamatan',
        'kabupaten_kota' => 'Kabupaten/kota',
        'provinsi' => 'Provinsi',
        'agama' => 'Agama',
        'kewarganegaraan' => 'Kewarganegaraan',
        'anak_ke' => 'Anak ke-',
        'status_yatim_piatu' => 'Status yatim/piatu',
        'kontak_utama' => 'Kontak utama',
    ];

    /** Wajib untuk ayah dan ibu yang masih hidup, selain status dan nama. Yang sudah meninggal cukup nama. */
    private const WAJIB_ORANG_TUA = [
        'tempat_lahir' => 'Tempat lahir',
        'tanggal_lahir' => 'Tanggal lahir',
        'agama' => 'Agama',
        'kewarganegaraan' => 'Kewarganegaraan',
        'pendidikan_terakhir' => 'Pendidikan terakhir',
        'pekerjaan' => 'Pekerjaan',
        'alamat_rumah' => 'Alamat rumah',
    ];

    /**
     * @param  Isian  $anak
     * @param  IsianOrangTua  $orangTua  isian per peran (`ayah`, `ibu`, `wali`)
     * @return array<string, string> pesan per field yang wajib tetapi kosong
     */
    public static function kurang(array $anak, array $orangTua): array
    {
        $kurang = [];

        foreach (self::WAJIB_ANAK as $kolom => $label) {
            if (self::kosong($anak[$kolom] ?? null)) {
                $kurang[$kolom] = "{$label} wajib diisi.";
            }
        }

        $status = self::statusYatimPiatu($anak);

        foreach ([Hubungan::Ayah, Hubungan::Ibu] as $peran) {
            $kurang += self::kurangOrangTua($peran, $orangTua[$peran->value] ?? [], $status?->sudahMeninggal($peran) ?? false);
        }

        return $kurang + self::kurangWali($orangTua['wali'] ?? []) + self::kurangNomorHp($orangTua, $status) + self::masalahKontakUtama($anak, $orangTua);
    }

    /**
     * Kontak utama harus orang yang datanya ada, masih hidup, dan punya nomor HP, karena nomor itu dipakai akun wali
     * otomatis. Berlaku juga untuk isian staf dan import yang belum lengkap.
     *
     * @param  Isian  $anak
     * @param  IsianOrangTua  $orangTua
     * @return array<string, string>
     */
    public static function masalahKontakUtama(array $anak, array $orangTua): array
    {
        $kontak = Hubungan::tryFrom((string) FormulirMurid::polos($anak['kontak_utama'] ?? null));

        if ($kontak === null) {
            return [];
        }

        $orang = $orangTua[$kontak->value] ?? [];
        $sebutan = strtolower($kontak->label());

        return match (true) {
            ! self::ada($orang) => ['kontak_utama' => "Data {$sebutan} belum diisi, jadi {$sebutan} tidak bisa dipilih sebagai kontak utama."],
            self::statusYatimPiatu($anak)?->sudahMeninggal($kontak) ?? false => [
                'kontak_utama' => "{$kontak->label()} sudah meninggal menurut status yatim/piatu. Pilih kontak utama lain.",
            ],
            self::kosong($orang['no_hp'] ?? null) => ["{$kontak->value}.no_hp" => "Nomor HP {$sebutan} wajib diisi karena {$sebutan} dipilih sebagai kontak utama."],
            default => [],
        };
    }

    /**
     * Butuh relasi `orangTua` (dimuat kalau belum).
     *
     * @return array<string, string>
     */
    public static function untukMurid(Murid $murid): array
    {
        $anak = array_map(FormulirMurid::polos(...), $murid->only(array_keys(self::WAJIB_ANAK)));
        $orangTua = $murid->orangTua->mapWithKeys(fn (OrangTua $orang): array => [$orang->peran->value => $orang->isian()])->all();

        return self::kurang($anak, $orangTua);
    }

    /**
     * @param  Isian|null  $orang
     * @return array<string, string>
     */
    private static function kurangOrangTua(Hubungan $peran, ?array $orang, bool $meninggal): array
    {
        $orang ??= [];
        $sebutan = strtolower($peran->label());

        if ($meninggal) {
            return self::kosong($orang['nama'] ?? null) ? ["{$peran->value}.nama" => "Nama {$sebutan} wajib diisi."] : [];
        }

        $kurang = [];

        foreach (['status' => 'Status', 'nama' => 'Nama', ...self::WAJIB_ORANG_TUA] as $kolom => $label) {
            $terisi = $kolom === 'alamat_rumah'
                ? filter_var($orang['alamat_sama_dengan_anak'] ?? false, FILTER_VALIDATE_BOOLEAN) || ! self::kosong($orang['alamat_rumah'] ?? null)
                : ! self::kosong($orang[$kolom] ?? null);

            if (! $terisi) {
                $kurang["{$peran->value}.{$kolom}"] = "{$label} {$sebutan} wajib diisi.";
            }
        }

        if (FormulirMurid::polos($orang['pekerjaan'] ?? null) === Pekerjaan::Lainnya->value && self::kosong($orang['pekerjaan_lainnya'] ?? null)) {
            $kurang["{$peran->value}.pekerjaan_lainnya"] = "Tulis pekerjaan {$sebutan}.";
        }

        return $kurang;
    }

    /**
     * Wali boleh tidak diisi; kalau diisi, hubungan, nama, dan nomor HP wajib.
     *
     * @param  Isian|null  $wali
     * @return array<string, string>
     */
    private static function kurangWali(?array $wali): array
    {
        if (! self::ada($wali)) {
            return [];
        }

        $wali ??= [];
        $kurang = [];

        foreach (['hubungan' => 'Hubungan wali dengan anak', 'nama' => 'Nama wali', 'no_hp' => 'Nomor HP wali'] as $kolom => $label) {
            if (self::kosong($wali[$kolom] ?? null)) {
                $kurang["wali.{$kolom}"] = "{$label} wajib diisi.";
            }
        }

        if (FormulirMurid::polos($wali['hubungan'] ?? null) === HubunganWali::Lainnya->value && self::kosong($wali['hubungan_lainnya'] ?? null)) {
            $kurang['wali.hubungan_lainnya'] = 'Tulis hubungan wali dengan anak.';
        }

        return $kurang;
    }

    /**
     * @param  IsianOrangTua  $orangTua
     * @return array<string, string>
     */
    private static function kurangNomorHp(array $orangTua, ?StatusYatimPiatu $status): array
    {
        $hidup = array_values(array_filter(['ayah', 'ibu', 'wali'], fn (string $peran): bool => ! ($status?->sudahMeninggal(Hubungan::from($peran)) ?? false)));

        foreach ($hidup as $peran) {
            if (! self::kosong($orangTua[$peran]['no_hp'] ?? null)) {
                return [];
            }
        }

        return [($hidup[0] ?? 'wali').'.no_hp' => 'Isi minimal satu nomor HP ayah, ibu, atau wali.'];
    }

    /**
     * @param  Isian  $anak
     */
    private static function statusYatimPiatu(array $anak): ?StatusYatimPiatu
    {
        return StatusYatimPiatu::tryFrom((string) FormulirMurid::polos($anak['status_yatim_piatu'] ?? null));
    }

    /**
     * @param  Isian|null  $orang
     */
    private static function ada(?array $orang): bool
    {
        foreach ($orang ?? [] as $kolom => $nilai) {
            if ($kolom !== 'alamat_sama_dengan_anak' && ! self::kosong($nilai)) {
                return true;
            }
        }

        return false;
    }

    private static function kosong(mixed $nilai): bool
    {
        return $nilai === null || $nilai === '' || $nilai === [];
    }
}
