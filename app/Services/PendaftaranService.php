<?php

namespace App\Services;

use App\Enums\JenisDokumen;
use App\Enums\StatusPendaftaran;
use App\Exceptions\BusinessRuleException;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pendaftaran;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Models\WaliMurid;
use App\Notifications\PendaftaranBaruNotification;
use App\Notifications\PendaftaranDiprosesNotification;
use App\Support\NomorUrut;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * PPDB (A6, B6.11). Pendaftaran selalu untuk tahun ajaran `ppdb.tahun_ajaran_id`. Kuota dihitung dari
 * pendaftaran selain yang ditolak di tahun ajaran itu. Alur: diajukan → diverifikasi → diterima, dan
 * diajukan/diverifikasi → ditolak. Menerima pendaftar membuat data murid dalam satu transaksi.
 */
class PendaftaranService
{
    private const FOLDER_DOKUMEN = 'ppdb';

    private const DIGIT_KODE = 4;

    public function __construct(
        private readonly PengaturanService $pengaturan,
        private readonly MediaService $media,
        private readonly MuridService $muridService,
        private readonly KelasService $kelasService,
    ) {}

    /**
     * Status PPDB untuk landing page dan form pendaftaran. `dibuka` hanya true kalau PPDB dibuka di pengaturan,
     * hari ini di antara tanggal buka dan tutup (yang terisi), dan tahun ajaran tujuannya ada. Kuota penuh
     * tidak mengubah `dibuka`; lihat `sisa_kuota`.
     *
     * @return array{dibuka: bool, tanggal_buka: string|null, tanggal_tutup: string|null, kuota: int, sisa_kuota: int, info: string, tahun_ajaran: array{id: int, nama: string}|null}
     */
    public function status(): array
    {
        $tahunAjaran = $this->tahunAjaranTujuan();
        $buka = $this->tanggal('ppdb.tanggal_buka');
        $tutup = $this->tanggal('ppdb.tanggal_tutup');
        $kuota = (int) $this->pengaturan->nilai('ppdb.kuota', 0);
        $hariIni = today();

        $dibuka = $this->pengaturan->nilai('ppdb.dibuka') === true
            && $tahunAjaran !== null
            && ($buka === null || $hariIni->gte($buka))
            && ($tutup === null || $hariIni->lte($tutup));

        return [
            'dibuka' => (bool) $dibuka,
            'tanggal_buka' => $buka?->toDateString(),
            'tanggal_tutup' => $tutup?->toDateString(),
            'kuota' => $kuota,
            'sisa_kuota' => $tahunAjaran === null ? 0 : (int) max(0, $kuota - $this->terpakai($tahunAjaran)),
            'info' => (string) $this->pengaturan->nilai('ppdb.info', ''),
            'tahun_ajaran' => $tahunAjaran === null ? null : ['id' => $tahunAjaran->id, 'nama' => $tahunAjaran->nama],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, UploadedFile|list<UploadedFile>>  $dokumen  per jenis dokumen; `lainnya` berupa daftar
     *
     * @throws BusinessRuleException
     */
    public function daftar(WaliMurid $wali, array $data, array $dokumen): Pendaftaran
    {
        $tahunAjaran = $this->pastikanBisaMendaftar($data['nik']);
        $path = $this->simpanDokumen($dokumen);

        try {
            $pendaftaran = DB::transaction(function () use ($wali, $data, $path, $tahunAjaran): Pendaftaran {
                TahunAjaran::query()->lockForUpdate()->findOrFail($tahunAjaran->id);
                if ($this->terpakai($tahunAjaran) >= (int) $this->pengaturan->nilai('ppdb.kuota', 0)) {
                    throw new BusinessRuleException("Kuota PPDB tahun ajaran {$tahunAjaran->nama} sudah penuh.");
                }

                $awalan = 'PPDB-'.$tahunAjaran->tanggal_mulai->year.'-';
                $pendaftaran = Pendaftaran::query()->create([
                    ...$data,
                    'kode' => NomorUrut::format($awalan, NomorUrut::berikutnya(Pendaftaran::query(), 'kode', $awalan, self::DIGIT_KODE), self::DIGIT_KODE),
                    'wali_murid_id' => $wali->id,
                    'tahun_ajaran_id' => $tahunAjaran->id,
                    'status' => StatusPendaftaran::Diajukan,
                ]);
                foreach ($path as [$jenis, $satu]) {
                    $pendaftaran->dokumen()->create(['jenis' => $jenis, 'path' => $satu]);
                }

                return $pendaftaran;
            });
        } catch (Throwable $e) {
            foreach ($path as [, $satu]) {
                $this->media->hapus($satu, MediaService::DISK_PRIVAT);
            }

            throw $e;
        }

        Notification::send(User::query()->kepalaSekolahAktif()->get(), new PendaftaranBaruNotification($pendaftaran));

        return $pendaftaran;
    }

    /**
     * @throws BusinessRuleException
     */
    public function verifikasi(Pendaftaran $pendaftaran, User $kepalaSekolah): Pendaftaran
    {
        $pendaftaran = DB::transaction(function () use ($pendaftaran, $kepalaSekolah): Pendaftaran {
            $pendaftaran = $this->kunci($pendaftaran, [StatusPendaftaran::Diajukan], 'diverifikasi');
            $pendaftaran->update(['status' => StatusPendaftaran::Diverifikasi, ...$this->jejakProses($kepalaSekolah)]);

            return $pendaftaran;
        });

        return $this->selesaikan($pendaftaran, $kepalaSekolah, 'diverifikasi', "Memverifikasi pendaftaran {$pendaftaran->kode}");
    }

    /**
     * Membuat murid (NIS dari tanggal mulai tahun ajaran tujuan), menautkan wali pendaftar sebagai kontak utama
     * dengan hubungan dari pendaftaran, menyalin pas foto menjadi foto murid, dan menempatkan murid di kelas
     * kalau dipilih (kelas harus di tahun ajaran tujuan, dengan cek kapasitas).
     *
     * @throws BusinessRuleException
     */
    public function terima(Pendaftaran $pendaftaran, ?Kelas $kelas, User $kepalaSekolah): Pendaftaran
    {
        if ($kelas !== null && $kelas->tahun_ajaran_id !== $pendaftaran->tahun_ajaran_id) {
            throw new BusinessRuleException("Kelas {$kelas->nama} bukan kelas tahun ajaran tujuan pendaftaran ini.");
        }

        $pasFoto = $pendaftaran->dokumen()->where('jenis', JenisDokumen::PasFoto)->value('path');
        $foto = is_string($pasFoto) && ! str_ends_with($pasFoto, '.pdf') ? $this->media->salinPrivat($pasFoto, MuridService::FOLDER_FOTO) : null;

        try {
            $pendaftaran = DB::transaction(function () use ($pendaftaran, $kelas, $kepalaSekolah, $foto): Pendaftaran {
                $pendaftaran = $this->kunci($pendaftaran, [StatusPendaftaran::Diverifikasi], 'diterima');
                $pendaftaran->loadMissing('tahunAjaran');

                $murid = $this->muridService->buatDenganFotoTersimpan([
                    'nik' => $pendaftaran->nik,
                    'nama_lengkap' => $pendaftaran->nama_lengkap,
                    'nama_panggilan' => $pendaftaran->nama_panggilan,
                    'jenis_kelamin' => $pendaftaran->jenis_kelamin,
                    'tempat_lahir' => $pendaftaran->tempat_lahir,
                    'tanggal_lahir' => $pendaftaran->tanggal_lahir->toDateString(),
                    'agama' => $pendaftaran->agama,
                    'alamat' => $pendaftaran->alamat,
                    'tanggal_masuk' => $pendaftaran->tahunAjaran->tanggal_mulai->toDateString(),
                ], $foto);
                $murid->waliMurid()->attach($pendaftaran->wali_murid_id, ['hubungan' => $pendaftaran->hubungan, 'is_kontak_utama' => true]);

                if ($kelas !== null) {
                    $this->kelasService->tempatkanMurid($kelas, [$murid->id]);
                }

                $pendaftaran->update(['status' => StatusPendaftaran::Diterima, 'murid_id' => $murid->id, ...$this->jejakProses($kepalaSekolah)]);

                return $pendaftaran;
            });
        } catch (Throwable $e) {
            $this->media->hapus($foto, MediaService::DISK_PRIVAT);

            throw $e;
        }

        return $this->selesaikan($pendaftaran, $kepalaSekolah, 'diterima', "Menerima pendaftaran {$pendaftaran->kode}", ['kelas_id' => $kelas?->id]);
    }

    /**
     * @throws BusinessRuleException
     */
    public function tolak(Pendaftaran $pendaftaran, string $alasan, User $kepalaSekolah): Pendaftaran
    {
        $pendaftaran = DB::transaction(function () use ($pendaftaran, $alasan, $kepalaSekolah): Pendaftaran {
            $pendaftaran = $this->kunci($pendaftaran, [StatusPendaftaran::Diajukan, StatusPendaftaran::Diverifikasi], 'ditolak');
            $pendaftaran->update(['status' => StatusPendaftaran::Ditolak, 'catatan' => $alasan, ...$this->jejakProses($kepalaSekolah)]);

            return $pendaftaran;
        });

        return $this->selesaikan($pendaftaran, $kepalaSekolah, 'ditolak', "Menolak pendaftaran {$pendaftaran->kode}", ['alasan' => $alasan]);
    }

    /**
     * @throws BusinessRuleException
     */
    private function pastikanBisaMendaftar(string $nik): TahunAjaran
    {
        $status = $this->status();
        $tahunAjaran = $this->tahunAjaranTujuan();

        if (! $status['dibuka'] || $tahunAjaran === null) {
            throw new BusinessRuleException('PPDB sedang ditutup. Lihat jadwal pendaftaran di halaman PPDB.');
        }
        if ($status['sisa_kuota'] === 0) {
            throw new BusinessRuleException("Kuota PPDB tahun ajaran {$tahunAjaran->nama} sudah penuh.");
        }

        // Pendaftar yang pernah ditolak boleh mendaftar ulang; pendaftaran lain dengan NIK yang sama (sedang
        // diproses atau sudah diterima) dan murid ber-NIK sama menandakan anak ini sudah tercatat.
        if (Murid::query()->where('nik', $nik)->exists()) {
            throw new BusinessRuleException('Anak dengan NIK ini sudah terdaftar sebagai murid. Hubungi sekolah untuk mendapatkan kode tautan.');
        }
        if (Pendaftaran::query()->where('nik', $nik)->where('status', '!=', StatusPendaftaran::Ditolak)->exists()) {
            throw new BusinessRuleException('Anak dengan NIK ini sudah punya pendaftaran PPDB yang sedang diproses atau sudah diterima.');
        }

        return $tahunAjaran;
    }

    /**
     * @param  array<string, UploadedFile|list<UploadedFile>>  $dokumen
     * @return list<array{0: JenisDokumen, 1: string}>
     */
    private function simpanDokumen(array $dokumen): array
    {
        $path = [];

        try {
            foreach ($dokumen as $jenis => $file) {
                foreach (is_array($file) ? $file : [$file] as $satu) {
                    $path[] = [JenisDokumen::from($jenis), $this->media->simpanDokumen($satu, MediaService::DISK_PRIVAT, self::FOLDER_DOKUMEN)];
                }
            }
        } catch (Throwable $e) {
            foreach ($path as [, $satu]) {
                $this->media->hapus($satu, MediaService::DISK_PRIVAT);
            }

            throw $e;
        }

        return $path;
    }

    /**
     * @param  list<StatusPendaftaran>  $dari
     *
     * @throws BusinessRuleException
     */
    private function kunci(Pendaftaran $pendaftaran, array $dari, string $aksi): Pendaftaran
    {
        $pendaftaran = Pendaftaran::query()->lockForUpdate()->findOrFail($pendaftaran->id);

        if (! in_array($pendaftaran->status, $dari, true)) {
            throw new BusinessRuleException("Pendaftaran {$pendaftaran->kode} berstatus {$pendaftaran->status->label()} sehingga tidak bisa {$aksi}.");
        }

        return $pendaftaran;
    }

    /**
     * @return array{diproses_oleh: int, diproses_at: Carbon}
     */
    private function jejakProses(User $kepalaSekolah): array
    {
        return ['diproses_oleh' => $kepalaSekolah->id, 'diproses_at' => now()];
    }

    /**
     * @param  array<string, mixed>  $properti
     */
    private function selesaikan(Pendaftaran $pendaftaran, User $kepalaSekolah, string $event, string $pesanLog, array $properti = []): Pendaftaran
    {
        activity('ppdb')->causedBy($kepalaSekolah)->performedOn($pendaftaran)->event($event)->withProperties($properti)->log($pesanLog);

        $pendaftaran->load(['waliMurid.user', 'murid.kelas']);
        $pendaftaran->waliMurid->user->notify(new PendaftaranDiprosesNotification($pendaftaran));

        return $pendaftaran;
    }

    private function tahunAjaranTujuan(): ?TahunAjaran
    {
        $id = $this->pengaturan->nilai('ppdb.tahun_ajaran_id');

        return is_numeric($id) ? TahunAjaran::query()->find((int) $id) : null;
    }

    private function terpakai(TahunAjaran $tahunAjaran): int
    {
        return Pendaftaran::query()->where('tahun_ajaran_id', $tahunAjaran->id)->where('status', '!=', StatusPendaftaran::Ditolak)->count();
    }

    private function tanggal(string $kunci): ?Carbon
    {
        $nilai = $this->pengaturan->nilai($kunci);

        return is_string($nilai) && $nilai !== '' ? Carbon::parse($nilai)->startOfDay() : null;
    }
}
