<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Exceptions\BusinessRuleException;
use App\Models\Guru;
use App\Models\User;
use App\Notifications\GuruBaruNotification;
use App\Notifications\GuruDisetujuiNotification;
use App\Notifications\GuruDitolakNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class GuruService
{
    private const PANJANG_PASSWORD_AWAL = 10;

    private const KOLOM_AKUN = ['name', 'email', 'no_hp'];

    private const KOLOM_PROFIL = [
        'nip', 'nuptk', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'alamat',
        'pendidikan_terakhir', 'jabatan', 'bisa_kelola_keuangan', 'tampil_di_landing',
    ];

    public function __construct(private readonly MediaService $media) {}

    /**
     * Pendaftaran mandiri dari halaman `/daftar-guru`: akun menunggu persetujuan Kepala Sekolah.
     *
     * @param  array{name: string, email: string, password: string, no_hp: string, jenis_kelamin: string}  $data
     */
    public function daftar(array $data): Guru
    {
        $guru = DB::transaction(function () use ($data): Guru {
            $user = User::query()->create([
                ...Arr::only($data, [...self::KOLOM_AKUN, 'password']),
                'role' => Role::Guru,
                'status' => StatusAkun::Pending,
            ]);

            return $user->guru()->create(['jenis_kelamin' => $data['jenis_kelamin']]);
        });

        Notification::send(
            User::query()->kepalaSekolahAktif()->get(),
            new GuruBaruNotification($guru->id, $data['name']),
        );

        return $guru;
    }

    /**
     * Akun yang dibuat Kepala Sekolah langsung aktif. Password awal acak dikembalikan sekali ke
     * pemanggil untuk disampaikan ke guru; yang disimpan hanya hash-nya.
     *
     * @param  array<string, mixed>  $data
     * @return array{guru: Guru, password_awal: string}
     */
    public function buat(array $data, ?UploadedFile $foto, User $kepalaSekolah): array
    {
        $passwordAwal = Str::password(self::PANJANG_PASSWORD_AWAL, symbols: false);

        $guru = DB::transaction(function () use ($data, $foto, $kepalaSekolah, $passwordAwal): Guru {
            $user = User::query()->create([
                ...Arr::only($data, self::KOLOM_AKUN),
                'password' => $passwordAwal,
                'role' => Role::Guru,
                'status' => StatusAkun::Aktif,
                'email_verified_at' => now(),
            ]);

            return $user->guru()->create([
                ...Arr::only($data, self::KOLOM_PROFIL),
                'foto_path' => $foto === null ? null : $this->media->simpanGambar($foto, MediaService::DISK_PUBLIK, 'guru'),
                'disetujui_oleh' => $kepalaSekolah->id,
                'disetujui_at' => now(),
            ]);
        });

        return ['guru' => $guru->load('user'), 'password_awal' => $passwordAwal];
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
            $guru->user->update(Arr::only($data, self::KOLOM_AKUN));
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
     * @throws BusinessRuleException
     */
    public function setujui(Guru $guru, User $kepalaSekolah): Guru
    {
        $this->pastikanMenungguPersetujuan($guru);

        DB::transaction(function () use ($guru, $kepalaSekolah): void {
            $guru->user->update(['status' => StatusAkun::Aktif]);
            $guru->update([
                'disetujui_oleh' => $kepalaSekolah->id,
                'disetujui_at' => now(),
                'alasan_penolakan' => null,
            ]);
        });

        activity('guru')->causedBy($kepalaSekolah)->performedOn($guru)->event('disetujui')
            ->log("Menyetujui pendaftaran guru {$guru->user->name}");
        $guru->user->notify(new GuruDisetujuiNotification);

        return $guru;
    }

    /**
     * @throws BusinessRuleException
     */
    public function tolak(Guru $guru, string $alasan, User $kepalaSekolah): Guru
    {
        $this->pastikanMenungguPersetujuan($guru);

        DB::transaction(function () use ($guru, $alasan): void {
            $guru->user->update(['status' => StatusAkun::Ditolak]);
            $guru->update(['alasan_penolakan' => $alasan]);
        });

        activity('guru')->causedBy($kepalaSekolah)->performedOn($guru)->event('ditolak')
            ->withProperties(['alasan' => $alasan])
            ->log("Menolak pendaftaran guru {$guru->user->name}");
        $guru->user->notify(new GuruDitolakNotification($alasan));

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

        if (! in_array($statusLama, [StatusAkun::Aktif, StatusAkun::Nonaktif], true)) {
            throw new BusinessRuleException('Status hanya bisa diubah untuk guru yang sudah disetujui. Proses pendaftaran lewat Setujui atau Tolak.');
        }

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

    private function pastikanMenungguPersetujuan(Guru $guru): void
    {
        if ($guru->user->status !== StatusAkun::Pending) {
            throw new BusinessRuleException("Pendaftaran ini sudah diproses (status: {$guru->user->status->label()}). Hanya pendaftaran yang menunggu persetujuan yang bisa disetujui atau ditolak.");
        }
    }
}
