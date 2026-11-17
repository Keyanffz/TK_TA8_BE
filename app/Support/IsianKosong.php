<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Menggabungkan isian dari wali ke data anak tanpa menimpa yang sudah terisi. Isian terisi yang dikirim dengan nilai
 * lain dicatat sebagai bentrok dan ditolak bersama-sama lewat `pastikanTanpaBentrok()`.
 */
final class IsianKosong
{
    /** @var array<string, string> */
    private array $bentrok = [];

    /** @var list<string> */
    private array $diisi = [];

    /**
     * @param  array<string, string>  $atribut  nama field untuk pesan, dari `FormulirMurid::atribut()`
     */
    public function __construct(private readonly array $atribut) {}

    /**
     * @param  array<string, mixed>  $lama
     * @param  array<string, mixed>  $baru
     * @return array<string, mixed> hanya isian yang baru diisi
     */
    public function gabung(array $lama, array $baru, string $awalan = ''): array
    {
        $hasil = [];

        foreach ($baru as $kolom => $nilai) {
            if (self::kosong($nilai) || self::sama($lama[$kolom] ?? null, $nilai)) {
                continue;
            }

            if (self::kosong($lama[$kolom] ?? null)) {
                $hasil[$kolom] = $nilai;
                $this->diisi[] = $awalan.$kolom;
            } else {
                $this->tandaiBentrok($awalan.$kolom);
            }
        }

        return $hasil;
    }

    /**
     * Alamat rumah dan centang "sama dengan alamat anak" dianggap satu isian: kalau salah satunya sudah terisi, keduanya
     * tidak bisa diubah wali.
     *
     * @param  array<string, mixed>  $lama
     * @param  array<string, mixed>  $baru
     * @return array<string, mixed> isian lengkap orang itu setelah digabung
     */
    public function gabungOrangTua(string $peran, array $lama, array $baru): array
    {
        $alamatTerisi = ! self::kosong($lama['alamat_sama_dengan_anak'] ?? null) || ! self::kosong($lama['alamat_rumah'] ?? null);

        if ($alamatTerisi) {
            $alamatSama = filter_var($baru['alamat_sama_dengan_anak'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $berubah = ($alamatSama && ! self::sama($lama['alamat_sama_dengan_anak'] ?? null, true))
                || (! self::kosong($baru['alamat_rumah'] ?? null) && ! self::sama($lama['alamat_rumah'] ?? null, $baru['alamat_rumah']));

            if ($berubah) {
                $this->tandaiBentrok("{$peran}.alamat_rumah");
            }

            unset($baru['alamat_sama_dengan_anak'], $baru['alamat_rumah']);
        }

        return [...$lama, ...$this->gabung($lama, $baru, "{$peran}.")];
    }

    /**
     * @throws ValidationException
     */
    public function pastikanTanpaBentrok(): void
    {
        if ($this->bentrok !== []) {
            throw ValidationException::withMessages($this->bentrok);
        }
    }

    /**
     * @return list<string>
     */
    public function diisi(): array
    {
        return $this->diisi;
    }

    private function tandaiBentrok(string $kunci): void
    {
        $nama = $this->atribut[$kunci] ?? str_replace('_', ' ', $kunci);
        $this->bentrok[$kunci] = ucfirst($nama).' sudah terisi. Perubahan data yang sudah ada dilakukan lewat sekolah.';
    }

    /**
     * `false` hanya dipakai centang alamat, dan centang yang kosong berarti belum diisi.
     */
    private static function kosong(mixed $nilai): bool
    {
        return $nilai === null || $nilai === '' || $nilai === [] || $nilai === false;
    }

    private static function sama(mixed $lama, mixed $baru): bool
    {
        if (is_array($lama) && is_array($baru)) {
            sort($lama);
            sort($baru);

            return $lama === $baru;
        }
        if (is_bool($lama) || is_bool($baru)) {
            return filter_var($lama, FILTER_VALIDATE_BOOLEAN) === filter_var($baru, FILTER_VALIDATE_BOOLEAN);
        }
        if (is_numeric($lama) && is_numeric($baru)) {
            return (float) $lama === (float) $baru;
        }

        return is_scalar($lama) && is_scalar($baru) && (string) $lama === (string) $baru;
    }
}
