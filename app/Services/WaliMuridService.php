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
     * Onboarding wali setelah login Google pertama: nomor HP, alamat, pekerjaan, dan NIK (opsional).
     *
     * @param  array{no_hp: string, alamat: string, pekerjaan: string, nik?: string|null}  $data
     */
    public function lengkapiProfil(User $user, array $data): User
    {
        $wali = $user->profilWaliMurid();

        DB::transaction(function () use ($user, $wali, $data): void {
            $user->update(['no_hp' => $data['no_hp']]);
            $wali->update([
                'alamat' => $data['alamat'],
                'pekerjaan' => $data['pekerjaan'],
                'nik' => $data['nik'] ?? $wali->nik,
                'profil_lengkap' => true,
            ]);
        });

        return $user;
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
}
