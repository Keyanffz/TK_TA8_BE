<?php

namespace App\Services;

use App\Enums\Hubungan;
use App\Enums\StatusMurid;
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
    public const HARI_BERLAKU = 14;

    /**
     * Kode baru menggantikan kode lama (kode lama langsung tidak berlaku). Unique index di kolom
     * `kode_tautan` tetap menjadi penjaga terakhir kalau dua kode acak kebetulan sama.
     *
     * @throws BusinessRuleException
     */
    public function buat(Murid $murid): Murid
    {
        if ($murid->status !== StatusMurid::Aktif) {
            throw new BusinessRuleException("Kode tautan hanya bisa dibuat untuk murid aktif. Status {$murid->nama_lengkap} saat ini {$murid->status->label()}.");
        }

        do {
            $kode = $this->kodeAcak();
        } while (Murid::withTrashed()->where('kode_tautan', $kode)->exists());

        $murid->update([
            'kode_tautan' => $kode,
            'kode_tautan_expired_at' => now()->addDays(self::HARI_BERLAKU),
        ]);

        return $murid;
    }

    /**
     * Mengosongkan kode yang sudah kedaluwarsa (command `kode-tautan:bersihkan`).
     *
     * @return int jumlah murid yang kodenya dikosongkan
     */
    public function bersihkanKedaluwarsa(bool $simulasi = false): int
    {
        $query = Murid::withTrashed()->whereNotNull('kode_tautan')->where('kode_tautan_expired_at', '<', now());

        return $simulasi ? $query->count() : $query->update(['kode_tautan' => null, 'kode_tautan_expired_at' => null]);
    }

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

    private function kodeAcak(): string
    {
        $karakter = Murid::KARAKTER_KODE_TAUTAN;
        $kode = '';

        for ($i = 0; $i < Murid::PANJANG_KODE_TAUTAN; $i++) {
            $kode .= $karakter[random_int(0, strlen($karakter) - 1)];
        }

        return $kode;
    }
}
