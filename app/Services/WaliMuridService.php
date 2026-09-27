<?php

namespace App\Services;

use App\Enums\Hubungan;
use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Exceptions\BusinessRuleException;
use App\Models\Murid;
use App\Models\User;
use App\Models\WaliMurid;
use Illuminate\Support\Facades\DB;

class WaliMuridService
{
    /**
     * Akun wali untuk murid yang belum tertaut ke wali mana pun (murid baru atau pendaftar PPDB tanpa login):
     * username NIS murid, password awal tanggal lahir anak (DDMMYYYY) yang wajib diganti saat login pertama, dan
     * nama sementara sampai wali melengkapi profilnya. Wali ini menjadi kontak utama murid.
     */
    public function buatAkunOtomatis(Murid $murid, Hubungan $hubungan, ?string $noHp, User $pelaku): WaliMurid
    {
        return DB::transaction(function () use ($murid, $hubungan, $noHp, $pelaku): WaliMurid {
            $user = User::query()->create([
                'name' => 'Wali '.$murid->nama_panggilan,
                'username' => $murid->nis,
                'password' => $murid->passwordAwalWali(),
                'wajib_ganti_password' => true,
                'role' => Role::WaliMurid,
                'status' => StatusAkun::Aktif,
                'no_hp' => $noHp,
            ]);
            $wali = $user->waliMurid()->create(['profil_lengkap' => false]);
            $murid->waliMurid()->attach($wali, ['hubungan' => $hubungan, 'is_kontak_utama' => true]);

            activity('akun')->causedBy($pelaku)->performedOn($user)->event('dibuat')
                ->withProperties(['murid_id' => $murid->id, 'username' => $user->username])
                ->log("Membuat akun wali murid {$user->username} untuk {$murid->nama_lengkap}");

            return $wali->setRelation('user', $user);
        });
    }

    /**
     * Akun otomatis milik murid ini yang belum pernah dipakai (password awal belum diganti dan tidak tertaut ke
     * anak lain) dinonaktifkan dan tautannya dilepas, supaya tidak ada akun menganggur dengan password yang
     * mudah ditebak. Akun yang sudah dipakai tidak disentuh.
     */
    public function lepasAkunOtomatisBelumDipakai(Murid $murid, User $pelaku): ?WaliMurid
    {
        $wali = $murid->waliMurid()->with('user')->withCount('murid')->get()
            ->first(fn (WaliMurid $calon): bool => $calon->user->username === $murid->nis
                && $calon->user->wajib_ganti_password
                && $calon->murid_count === 1);

        if ($wali === null) {
            return null;
        }

        DB::transaction(function () use ($murid, $wali, $pelaku): void {
            $murid->waliMurid()->detach($wali->id);
            $wali->user->update(['status' => StatusAkun::Nonaktif]);
            $wali->user->tokens()->delete();

            activity('akun')->causedBy($pelaku)->performedOn($wali->user)->event('dinonaktifkan')
                ->withProperties(['murid_id' => $murid->id, 'username' => $wali->user->username])
                ->log("Menonaktifkan akun wali murid {$wali->user->username} yang belum dipakai dan melepasnya dari {$murid->nama_lengkap}");
        });

        return $wali;
    }

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
