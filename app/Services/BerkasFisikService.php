<?php

namespace App\Services;

use App\Enums\StatusPendaftaran;
use App\Exceptions\BusinessRuleException;
use App\Models\Murid;
use App\Models\Pendaftaran;
use App\Models\User;

/**
 * Centang Kartu Keluarga dan Akta Kelahiran yang diserahkan langsung ke sekolah. Berkas tidak diunggah; sistem hanya
 * mencatat siapa yang mencentang dan kapan. Saat pendaftaran diterima, centangnya ikut disalin ke murid.
 */
class BerkasFisikService
{
    /** Kunci request => awalan kolom. */
    public const BERKAS = ['kartu_keluarga' => 'kk', 'akta_kelahiran' => 'akta'];

    /**
     * Berkas yang sudah dicentang tidak diubah waktunya kalau dicentang lagi; `false` menghapus centang.
     *
     * @param  array<string, bool>  $centang
     *
     * @throws BusinessRuleException
     */
    public function centang(Murid|Pendaftaran $pemilik, array $centang, User $pelaku): void
    {
        if ($pemilik instanceof Pendaftaran && ! in_array($pemilik->status, [StatusPendaftaran::Diajukan, StatusPendaftaran::Diverifikasi], true)) {
            throw new BusinessRuleException("Pendaftaran {$pemilik->kode} berstatus {$pemilik->status->label()}, jadi centang berkasnya tidak bisa diubah. Berkas murid yang sudah diterima diubah di detail murid.");
        }

        foreach (self::BERKAS as $kunci => $awalan) {
            if (! array_key_exists($kunci, $centang)) {
                continue;
            }
            if (! $centang[$kunci]) {
                $pemilik->forceFill(["{$awalan}_diterima_at" => null, "{$awalan}_diterima_oleh" => null]);
            } elseif ($pemilik->getAttribute("{$awalan}_diterima_at") === null) {
                $pemilik->forceFill(["{$awalan}_diterima_at" => now(), "{$awalan}_diterima_oleh" => $pelaku->id]);
            }
        }

        if (! $pemilik->isDirty()) {
            return;
        }

        $pemilik->save();
        $nama = $pemilik instanceof Murid ? $pemilik->nama_lengkap : "{$pemilik->nama_lengkap} ({$pemilik->kode})";

        activity($pemilik instanceof Murid ? 'murid' : 'ppdb')->causedBy($pelaku)->performedOn($pemilik)->event('berkas_diubah')
            ->withProperties($centang)
            ->log("Mengubah centang berkas fisik {$nama}");
    }
}
