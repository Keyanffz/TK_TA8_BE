<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Exceptions\BusinessRuleException;
use App\Models\Guru;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class GuruService
{
    private const KOLOM_AKUN = ['name', 'email', 'no_hp'];

    private const KOLOM_PROFIL = [
        'nip', 'nuptk', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'alamat',
        'pendidikan_terakhir', 'jabatan', 'bisa_kelola_keuangan', 'tampil_di_landing',
    ];

    public function __construct(private readonly MediaService $media) {}

    /**
     * Guru tidak punya password: login hanya lewat Google dengan email yang didaftarkan di sini.
     *
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data, ?UploadedFile $foto): Guru
    {
        $guru = DB::transaction(function () use ($data, $foto): Guru {
            $user = User::query()->create([
                ...Arr::only($data, self::KOLOM_AKUN),
                'role' => Role::Guru,
                'status' => StatusAkun::Aktif,
            ]);

            return $user->guru()->create([
                ...Arr::only($data, self::KOLOM_PROFIL),
                'foto_path' => $foto === null ? null : $this->media->simpanGambar($foto, MediaService::DISK_PUBLIK, 'guru'),
            ]);
        });

        return $guru->load('user');
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function perbarui(Guru $guru, array $data, ?UploadedFile $foto, User $kepalaSekolah): Guru
    {
        $izinKeuanganBaru = array_key_exists('bisa_kelola_keuangan', $data) ? (bool) $data['bisa_kelola_keuangan'] : null;
        $izinKeuanganBerubah = $izinKeuanganBaru !== null && $izinKeuanganBaru !== $guru->bisa_kelola_keuangan;

        if ($izinKeuanganBerubah && $guru->milikKepalaSekolah()) {
            throw new BusinessRuleException('Izin keuangan profil Kepala Sekolah tidak bisa diubah karena Kepala Sekolah selalu menjadi petugas keuangan.');
        }

        $fotoLama = $guru->foto_path;

        DB::transaction(function () use ($guru, $data, $foto): void {
            $guru->user->fill(Arr::only($data, self::KOLOM_AKUN));

            // Akun Google yang terikat milik email lama; email baru harus bisa login dengan akun Google-nya sendiri.
            if ($guru->user->isDirty('email')) {
                $guru->user->google_sub = null;
            }

            $guru->user->save();
            $guru->fill(Arr::only($data, self::KOLOM_PROFIL));

            if ($foto !== null) {
                $guru->foto_path = $this->media->simpanGambar($foto, MediaService::DISK_PUBLIK, 'guru');
            }

            $guru->save();
        });

        if ($foto !== null) {
            $this->media->hapus($fotoLama, MediaService::DISK_PUBLIK);
        }

        if ($izinKeuanganBerubah) {
            activity('guru')->causedBy($kepalaSekolah)->performedOn($guru)
                ->event($izinKeuanganBaru ? 'izin_keuangan_diberikan' : 'izin_keuangan_dicabut')
                ->log(($izinKeuanganBaru ? 'Memberi' : 'Mencabut')." izin kelola keuangan untuk {$guru->user->name}");
        }

        return $guru;
    }

    /**
     * Melepas akun Google yang terikat, misalnya karena guru membuat ulang akun Google dengan email yang sama. Login
     * Google berikutnya dengan email itu mengikat akun Google yang dipakai saat itu. Sesi yang sedang berjalan tidak
     * dicabut; untuk itu nonaktifkan akunnya.
     *
     * @throws BusinessRuleException
     */
    public function resetGoogle(Guru $guru, User $kepalaSekolah): Guru
    {
        if ($guru->user->google_sub === null) {
            throw new BusinessRuleException("{$guru->user->name} belum pernah masuk dengan Google, jadi tidak ada tautan Google yang perlu direset.");
        }

        $guru->user->forceFill(['google_sub' => null])->save();

        activity('akun')->causedBy($kepalaSekolah)->performedOn($guru->user)->event('google_direset')
            ->log("Mereset tautan Google akun {$guru->user->name}");

        return $guru;
    }

    /**
     * Menonaktifkan akun mencabut semua tokennya (B4), sehingga guru langsung keluar dari semua perangkat.
     *
     * @throws BusinessRuleException
     */
    public function ubahStatus(Guru $guru, StatusAkun $statusBaru, User $kepalaSekolah): Guru
    {
        if ($guru->milikKepalaSekolah()) {
            throw new BusinessRuleException('Profil Kepala Sekolah tidak bisa dinonaktifkan.');
        }

        $statusLama = $guru->user->status;

        DB::transaction(function () use ($guru, $statusBaru): void {
            $guru->user->update(['status' => $statusBaru]);

            if ($statusBaru === StatusAkun::Nonaktif) {
                $guru->user->tokens()->delete();
            }
        });

        activity('akun')->causedBy($kepalaSekolah)->performedOn($guru->user)->event('status_diubah')
            ->withProperties(['dari' => $statusLama->value, 'ke' => $statusBaru->value])
            ->log("Mengubah status akun guru {$guru->user->name} menjadi {$statusBaru->label()}");

        return $guru;
    }
}
