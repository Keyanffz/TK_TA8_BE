<?php

namespace App\Services;

use App\Enums\Hubungan;
use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Enums\StatusMurid;
use App\Exceptions\BusinessRuleException;
use App\Models\Murid;
use App\Models\User;
use App\Models\WaliMurid;
use App\Notifications\AnakTertautNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

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
     * Wali yang sudah login menautkan kakak/adik ke akunnya dengan NIS dan tanggal lahir anak. Batas percobaan
     * diatur rate limiter `tambah-anak` di route. Akun otomatis anak itu yang belum pernah dipakai dinonaktifkan;
     * kalau sudah dipakai, anak tertaut ke kedua akun. Wali ini menjadi kontak utama kalau anak tidak punya wali
     * lain.
     *
     * @throws BusinessRuleException
     */
    public function tambahAnak(WaliMurid $wali, string $nis, Carbon $tanggalLahir, Hubungan $hubungan): Murid
    {
        $murid = Murid::query()->where('nis', $nis)->first();

        // Satu pesan untuk NIS dan tanggal lahir, supaya percobaan tidak bisa memastikan NIS mana yang terdaftar.
        if ($murid === null || ! $murid->tanggal_lahir->isSameDay($tanggalLahir)) {
            throw ValidationException::withMessages(['nis' => ['NIS atau tanggal lahir anak tidak cocok. Periksa kembali NIS di kartu akun dari sekolah.']]);
        }
        if ($murid->status !== StatusMurid::Aktif) {
            throw new BusinessRuleException("{$murid->nama_panggilan} tidak lagi berstatus aktif di sekolah sehingga tidak bisa ditambahkan. Hubungi pihak sekolah.");
        }
        if ($murid->waliMurid()->whereKey($wali->id)->exists()) {
            throw new BusinessRuleException("{$murid->nama_panggilan} sudah tertaut ke akun Anda.");
        }

        $waliLain = DB::transaction(function () use ($wali, $murid, $hubungan) {
            Murid::query()->lockForUpdate()->findOrFail($murid->id);
            $this->lepasAkunOtomatisBelumDipakai($murid, $wali->user);
            $waliLain = $murid->waliMurid()->with('user')->get();

            $murid->waliMurid()->attach($wali, ['hubungan' => $hubungan, 'is_kontak_utama' => $waliLain->isEmpty()]);

            activity('wali')->causedBy($wali->user)->performedOn($murid)->event('tertaut')
                ->withProperties(['wali_murid_id' => $wali->id, 'hubungan' => $hubungan->value])
                ->log("{$wali->user->name} menambahkan {$murid->nama_lengkap} ke akunnya");

            return $waliLain;
        });

        Notification::send(
            User::query()->kepalaSekolahAktif()->get()->merge($waliLain->pluck('user')),
            new AnakTertautNotification($murid->id, $murid->nama_panggilan, $wali->user->name, $hubungan->label()),
        );

        return $murid;
    }

    /**
     * Akun otomatis milik murid ini yang belum pernah dipakai (password awal belum diganti dan tidak tertaut ke
     * anak lain) dinonaktifkan dan tautannya dilepas, supaya tidak ada akun menganggur dengan password yang
     * mudah ditebak. Akun yang sudah dipakai tidak disentuh.
     */
    public function lepasAkunOtomatisBelumDipakai(Murid $murid, User $pelaku): ?WaliMurid
    {
        $wali = $this->akunOtomatisBelumDipakai($murid);

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
     * Onboarding dan ubah profil oleh wali sendiri. `profil_lengkap` menjadi true setelah nomor HP, alamat, dan
     * pekerjaan terisi.
     *
     * @param  array{nama: string, no_hp: string, alamat?: string|null, pekerjaan?: string|null, nik?: string|null}  $data
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
     * Setelah tanggal lahir murid dikoreksi, password awal akun otomatisnya yang belum pernah dipakai ikut diganti
     * ke tanggal lahir baru, supaya kartu akun tetap berlaku. Akun yang sudah dipakai tidak disentuh.
     */
    public function sesuaikanPasswordAwal(Murid $murid, User $pelaku): ?WaliMurid
    {
        $wali = $this->akunOtomatisBelumDipakai($murid);

        if ($wali === null) {
            return null;
        }

        $wali->user->forceFill(['password' => $murid->passwordAwalWali()])->save();

        activity('akun')->causedBy($pelaku)->performedOn($wali->user)->event('password_disesuaikan')
            ->withProperties(['murid_id' => $murid->id])
            ->log("Menyesuaikan password awal wali murid {$wali->user->username} dengan tanggal lahir baru {$murid->nama_lengkap}");

        return $wali;
    }

    /**
     * Mengembalikan password wali ke tanggal lahir anak yang dia jadi kontak utamanya, lalu mewajibkan ganti
     * password dan mencabut semua sesi. Kalau kontak utama untuk beberapa anak, dipakai anak yang NIS-nya menjadi
     * username, lalu anak yang paling awal tertaut. Status akun tidak diubah.
     *
     * @return Murid anak yang tanggal lahirnya menjadi password
     *
     * @throws BusinessRuleException
     */
    public function resetPassword(WaliMurid $wali, User $kepalaSekolah): Murid
    {
        $anak = $wali->murid()->wherePivot('is_kontak_utama', true)->orderByPivot('created_at')->orderBy('murid.id')->get();
        $acuan = $anak->firstWhere('nis', $wali->user->username) ?? $anak->first();

        if ($acuan === null) {
            throw new BusinessRuleException("{$wali->user->name} bukan kontak utama anak mana pun, jadi password awalnya tidak bisa ditentukan. Jadikan wali ini kontak utama salah satu anak terlebih dahulu.");
        }

        DB::transaction(function () use ($wali, $acuan): void {
            $wali->user->forceFill(['password' => $acuan->passwordAwalWali(), 'wajib_ganti_password' => true])->save();
            $wali->user->tokens()->delete();
        });

        activity('akun')->causedBy($kepalaSekolah)->performedOn($wali->user)->event('password_direset')
            ->withProperties(['murid_id' => $acuan->id])
            ->log("Mengembalikan password wali murid {$wali->user->name} ke tanggal lahir {$acuan->nama_lengkap}");

        return $acuan;
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
     * @param  array{nama?: string, no_hp?: string, alamat?: string|null, pekerjaan?: string|null, nik?: string|null}  $data
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

    /**
     * Akun otomatis murid ini yang belum pernah dipakai: username sama dengan NIS murid, password awal belum
     * diganti, dan hanya tertaut ke murid ini (akun keluarga yang password-nya direset tidak termasuk).
     */
    private function akunOtomatisBelumDipakai(Murid $murid): ?WaliMurid
    {
        return $murid->waliMurid()->with('user')->withCount('murid')->get()
            ->first(fn (WaliMurid $calon): bool => $calon->user->username === $murid->nis
                && $calon->user->wajib_ganti_password
                && $calon->murid_count === 1);
    }
}
