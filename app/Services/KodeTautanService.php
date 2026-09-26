<?php

namespace App\Services;

use App\Enums\Hubungan;
use App\Exceptions\BusinessRuleException;
use App\Models\Murid;
use App\Models\User;
use App\Models\WaliMurid;
use App\Notifications\AnakTertautNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Penautan anak ke akun wali memakai kode tautan dari sekolah (A2.2, B6.6). Satu kode bisa dipakai
 * lebih dari satu wali (ayah dan ibu) selama belum kedaluwarsa. Batas percobaan diatur rate limiter
 * `tautkan-anak` di route.
 */
class KodeTautanService
{
    /**
     * @throws BusinessRuleException
     */
    public function tautkan(WaliMurid $wali, string $kode, Carbon $tanggalLahir, Hubungan $hubungan): Murid
    {
        $murid = Murid::query()->where('kode_tautan', $kode)->first();

        if ($murid === null) {
            throw ValidationException::withMessages(['kode' => ['Kode tautan tidak ditemukan. Periksa kembali kode yang diberikan sekolah.']]);
        }

        if ($murid->kode_tautan_expired_at === null || $murid->kode_tautan_expired_at->isPast()) {
            throw ValidationException::withMessages(['kode' => ['Kode tautan sudah kedaluwarsa. Minta kode baru ke pihak sekolah.']]);
        }

        if (! $murid->tanggal_lahir->isSameDay($tanggalLahir)) {
            throw ValidationException::withMessages(['tanggal_lahir' => ['Tanggal lahir tidak cocok dengan data anak untuk kode ini.']]);
        }

        if ($murid->waliMurid()->whereKey($wali->id)->exists()) {
            throw new BusinessRuleException("{$murid->nama_panggilan} sudah tertaut ke akun Anda.");
        }

        $waliLain = $murid->waliMurid()->with('user')->get();

        $murid->waliMurid()->attach($wali, [
            'hubungan' => $hubungan,
            'is_kontak_utama' => $waliLain->isEmpty(),
        ]);

        activity('wali')->causedBy($wali->user)->performedOn($murid)->event('tertaut')
            ->withProperties(['wali_murid_id' => $wali->id, 'hubungan' => $hubungan->value])
            ->log("{$wali->user->name} menautkan akun ke {$murid->nama_lengkap}");

        Notification::send(
            User::query()->kepalaSekolahAktif()->get()->merge($waliLain->pluck('user')),
            new AnakTertautNotification($murid->id, $murid->nama_panggilan, $wali->user->name, $hubungan->label()),
        );

        return $murid;
    }
}
