<?php

namespace App\Services;

use App\Enums\StatusAkun;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\WaliMurid;
use Illuminate\Support\Facades\DB;

class WaliMuridService
{
    /**
     * Onboarding dan ubah profil oleh wali sendiri. Sebagian field boleh dikirim; `profil_lengkap` menjadi
     * true setelah nomor HP, alamat, dan pekerjaan terisi.
     *
     * @param  array{no_hp?: string, alamat?: string, pekerjaan?: string, nik?: string|null}  $data
     */
    public function lengkapiProfil(User $user, array $data): User
    {
        $this->simpanData($user->profilWaliMurid()->setRelation('user', $user), $data);

        return $user;
    }

    /**
     * Perubahan data wali oleh Kepala Sekolah, dicatat di log aktivitas (nama field saja, tanpa nilainya,
     * karena berisi NIK dan nomor HP).
     *
     * @param  array{nama?: string, no_hp?: string, alamat?: string, pekerjaan?: string, nik?: string|null}  $data
     */
    public function perbarui(WaliMurid $wali, array $data, User $kepalaSekolah): WaliMurid
    {
        $diubah = $this->simpanData($wali, $data);

        if ($diubah !== []) {
            activity('akun')->causedBy($kepalaSekolah)->performedOn($wali->user)->event('data_diubah')
                ->withProperties(['field' => $diubah])
                ->log("Mengubah data wali murid {$wali->user->name}: ".implode(', ', $diubah));
        }

        return $wali;
    }

    /**
     * Menonaktifkan akun mencabut semua tokennya (B4).
     *
     * @throws BusinessRuleException
     */
    public function ubahStatus(WaliMurid $wali, StatusAkun $statusBaru, User $kepalaSekolah): WaliMurid
    {
        $statusLama = $wali->user->status;

        if (! in_array($statusLama, [StatusAkun::Aktif, StatusAkun::Nonaktif], true)) {
            throw new BusinessRuleException("Status akun wali murid ini ({$statusLama->label()}) tidak bisa diubah menjadi {$statusBaru->label()}.");
        }

        DB::transaction(function () use ($wali, $statusBaru): void {
            $wali->user->update(['status' => $statusBaru]);

            if ($statusBaru === StatusAkun::Nonaktif) {
                $wali->user->tokens()->delete();
            }
        });

        activity('akun')->causedBy($kepalaSekolah)->performedOn($wali->user)->event('status_diubah')
            ->withProperties(['dari' => $statusLama->value, 'ke' => $statusBaru->value])
            ->log("Mengubah status akun wali murid {$wali->user->name} menjadi {$statusBaru->label()}");

        return $wali;
    }

    /**
     * @param  array{nama?: string, no_hp?: string, alamat?: string, pekerjaan?: string, nik?: string|null}  $data
     * @return list<string> nama field yang nilainya berubah
     */
    private function simpanData(WaliMurid $wali, array $data): array
    {
        $user = $wali->user;
        $user->fill(array_filter(['name' => $data['nama'] ?? null, 'no_hp' => $data['no_hp'] ?? null], fn (?string $nilai): bool => $nilai !== null));
        $wali->fill(array_intersect_key($data, array_flip(['alamat', 'pekerjaan', 'nik'])));

        $diubah = array_map(fn (string $kolom): string => $kolom === 'name' ? 'nama' : $kolom, [...array_keys($user->getDirty()), ...array_keys($wali->getDirty())]);
        $wali->profil_lengkap = filled($user->no_hp) && filled($wali->alamat) && filled($wali->pekerjaan);

        DB::transaction(function () use ($user, $wali): void {
            $user->save();
            $wali->save();
        });

        return $diubah;
    }
}
