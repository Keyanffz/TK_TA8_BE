<?php

namespace App\Services;

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
use App\Support\FormulirMurid;
use App\Support\NomorUrut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * PPDB (A6, B6.11). Pendaftaran selalu untuk tahun ajaran `ppdb.tahun_ajaran_id`. Kuota dihitung dari
 * pendaftaran selain yang ditolak dan mengundurkan diri di tahun ajaran itu. Alur: diajukan → diverifikasi →
 * diterima, diajukan/diverifikasi → ditolak, dan diajukan/diverifikasi/diterima → mengundurkan diri. `diverifikasi`
 * berarti data formulir sudah dicek sekolah; Kartu Keluarga dan akta kelahiran dicentang terpisah. Menerima
 * pendaftar membuat data murid dalam satu transaksi. Pendaftaran tanpa login belum punya wali; akun walinya dibuat
 * saat Kepala Sekolah mengisi NIS murid itu.
 */
class PendaftaranService
{
    private const DIGIT_KODE = 4;

    /** Status yang tidak dihitung kuota dan tidak menahan NIK anak untuk mendaftar lagi. */
    private const STATUS_SELESAI = [StatusPendaftaran::Ditolak, StatusPendaftaran::MengundurkanDiri];

    public function __construct(
        private readonly PengaturanService $pengaturan,
        private readonly MuridService $muridService,
        private readonly KelasService $kelasService,
        private readonly OrangTuaService $orangTua,
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
     * @param  WaliMurid|null  $wali  null untuk pendaftaran tanpa login
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function daftar(?WaliMurid $wali, array $data): Pendaftaran
    {
        $tahunAjaran = $this->pastikanBisaMendaftar($data['nik']);

        $pendaftaran = DB::transaction(function () use ($wali, $data, $tahunAjaran): Pendaftaran {
            TahunAjaran::query()->lockForUpdate()->findOrFail($tahunAjaran->id);
            if ($this->terpakai($tahunAjaran) >= (int) $this->pengaturan->nilai('ppdb.kuota', 0)) {
                throw new BusinessRuleException("Kuota PPDB tahun ajaran {$tahunAjaran->nama} sudah penuh.");
            }

            $awalan = 'PPDB-'.$tahunAjaran->tanggal_mulai->year.'-';

            $pendaftaran = Pendaftaran::query()->create([
                ...Arr::except($data, FormulirMurid::PERAN),
                'kode' => NomorUrut::format($awalan, NomorUrut::berikutnya(Pendaftaran::query(), 'kode', $awalan, self::DIGIT_KODE), self::DIGIT_KODE),
                'wali_murid_id' => $wali?->id,
                'tahun_ajaran_id' => $tahunAjaran->id,
                'status' => StatusPendaftaran::Diajukan,
            ]);
            $this->orangTua->simpan($pendaftaran, Arr::only($data, FormulirMurid::PERAN));

            return $pendaftaran;
        });

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
     * Membuat murid tanpa NIS dan tanpa foto dari isian formulir, orang tua, dan centang berkas pendaftaran, menautkan
     * wali pendaftar sebagai kontak utama dengan hubungan dari pendaftaran, dan menempatkan murid di kelas kalau
     * dipilih (kelas harus di tahun ajaran tujuan, dengan cek kapasitas). Pendaftaran tanpa login belum punya wali:
     * akunnya dibuat saat Kepala Sekolah mengisi NIS (`NisMuridService::isi()`), dari kontak utama formulir.
     *
     * @throws BusinessRuleException
     */
    public function terima(Pendaftaran $pendaftaran, ?Kelas $kelas, User $kepalaSekolah): Pendaftaran
    {
        if ($kelas !== null && $kelas->tahun_ajaran_id !== $pendaftaran->tahun_ajaran_id) {
            throw new BusinessRuleException("Kelas {$kelas->nama} bukan kelas tahun ajaran tujuan pendaftaran ini.");
        }

        $pendaftaran = DB::transaction(function () use ($pendaftaran, $kelas, $kepalaSekolah): Pendaftaran {
            $pendaftaran = $this->kunci($pendaftaran, [StatusPendaftaran::Diverifikasi], 'diterima');
            $pendaftaran->loadMissing('tahunAjaran');

            $murid = $this->muridService->buat([
                ...$pendaftaran->only(['nik', 'nama_lengkap', 'nama_panggilan', 'jenis_kelamin', 'tempat_lahir', 'agama', 'alamat', 'anak_ke']),
                ...$pendaftaran->only([...FormulirMurid::KOLOM_ANAK, ...FormulirMurid::KOLOM_BERKAS]),
                'tanggal_lahir' => $pendaftaran->tanggal_lahir->toDateString(),
                'tanggal_masuk' => $pendaftaran->tahunAjaran->tanggal_mulai->toDateString(),
                ...$this->orangTua->isianDari($pendaftaran),
            ], null);
            if ($pendaftaran->wali_murid_id !== null) {
                $murid->waliMurid()->attach($pendaftaran->wali_murid_id, ['hubungan' => $pendaftaran->hubungan, 'is_kontak_utama' => true]);
            }

            if ($kelas !== null) {
                $this->kelasService->tempatkanMurid($kelas, [$murid->id]);
            }

            $pendaftaran->update(['status' => StatusPendaftaran::Diterima, 'murid_id' => $murid->id, ...$this->jejakProses($kepalaSekolah)]);

            return $pendaftaran;
        });

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
     * Pendaftar yang tidak jadi masuk. Datanya tetap disimpan. Pendaftar yang sudah diterima hanya boleh mundur kalau
     * muridnya belum punya tagihan, rapor, atau absensi; murid itu dihapus (NIS, NISN, NIK dikosongkan, akun wali
     * otomatis yang belum dipakai dinonaktifkan) dan status mundurnya tidak bisa dibatalkan. Tanpa notifikasi.
     *
     * @throws BusinessRuleException
     */
    public function mengundurkanDiri(Pendaftaran $pendaftaran, ?string $alasan, User $kepalaSekolah): Pendaftaran
    {
        $pendaftaran = DB::transaction(function () use ($pendaftaran, $alasan, $kepalaSekolah): Pendaftaran {
            $pendaftaran = $this->kunci($pendaftaran, [StatusPendaftaran::Diajukan, StatusPendaftaran::Diverifikasi, StatusPendaftaran::Diterima], 'mengundurkan diri');
            $sebelum = $pendaftaran->status;

            if ($sebelum === StatusPendaftaran::Diterima) {
                $this->hapusMuridPendaftar($pendaftaran, $kepalaSekolah);
            }

            $pendaftaran->update([
                'status' => StatusPendaftaran::MengundurkanDiri,
                'status_sebelum_mundur' => $sebelum,
                'catatan' => $alasan,
                ...$this->jejakProses($kepalaSekolah),
            ]);

            return $pendaftaran;
        });

        activity('ppdb')->causedBy($kepalaSekolah)->performedOn($pendaftaran)->event('mengundurkan_diri')
            ->withProperties(['dari' => $pendaftaran->status_sebelum_mundur?->value, 'alasan' => $alasan])
            ->log("Mencatat {$pendaftaran->kode} mengundurkan diri");

        return $pendaftaran;
    }

    /**
     * Mengembalikan pendaftar yang mundur sebelum diterima ke status sebelumnya, selama kuota masih ada dan NIK anak
     * belum dipakai pendaftaran lain atau murid.
     *
     * @throws BusinessRuleException
     */
    public function batalkanMundur(Pendaftaran $pendaftaran, User $kepalaSekolah): Pendaftaran
    {
        $pendaftaran = DB::transaction(function () use ($pendaftaran, $kepalaSekolah): Pendaftaran {
            $pendaftaran = $this->kunci($pendaftaran, [StatusPendaftaran::MengundurkanDiri], 'dikembalikan');
            $sebelum = $pendaftaran->status_sebelum_mundur;

            if (! in_array($sebelum, [StatusPendaftaran::Diajukan, StatusPendaftaran::Diverifikasi], true)) {
                throw new BusinessRuleException("{$pendaftaran->nama_lengkap} mengundurkan diri setelah diterima dan data muridnya sudah dihapus, jadi statusnya tidak bisa dikembalikan. Minta pendaftar mendaftar ulang.");
            }

            $this->pastikanNikBelumDipakai($pendaftaran->nik, $pendaftaran->id);
            $tahunAjaran = TahunAjaran::query()->lockForUpdate()->findOrFail($pendaftaran->tahun_ajaran_id);

            if ($this->terpakai($tahunAjaran) >= (int) $this->pengaturan->nilai('ppdb.kuota', 0)) {
                throw new BusinessRuleException("Kuota PPDB tahun ajaran {$tahunAjaran->nama} sudah penuh, jadi pendaftaran {$pendaftaran->kode} tidak bisa dikembalikan.");
            }

            $pendaftaran->update(['status' => $sebelum, 'status_sebelum_mundur' => null, 'catatan' => null, ...$this->jejakProses($kepalaSekolah)]);

            return $pendaftaran;
        });

        activity('ppdb')->causedBy($kepalaSekolah)->performedOn($pendaftaran)->event('mundur_dibatalkan')
            ->withProperties(['ke' => $pendaftaran->status->value])
            ->log("Membatalkan status mengundurkan diri {$pendaftaran->kode}");

        return $pendaftaran;
    }

    /**
     * @throws BusinessRuleException
     */
    private function hapusMuridPendaftar(Pendaftaran $pendaftaran, User $kepalaSekolah): void
    {
        $murid = Murid::query()->lockForUpdate()->find($pendaftaran->murid_id);

        if ($murid === null) {
            return;
        }
        if ($murid->tagihan()->exists() || $murid->rapor()->exists() || $murid->absensiMurid()->exists()) {
            throw new BusinessRuleException("{$murid->nama_lengkap} sudah punya tagihan, rapor, atau absensi, jadi dianggap sudah mulai sekolah. Ubah status muridnya menjadi Keluar.");
        }

        $this->muridService->hapusData($murid, $kepalaSekolah);
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

        $this->pastikanNikBelumDipakai($nik);

        return $tahunAjaran;
    }

    /**
     * Pendaftar yang pernah ditolak atau mundur boleh mendaftar ulang; pendaftaran lain dengan NIK yang sama (sedang
     * diproses atau sudah diterima) dan murid ber-NIK sama menandakan anak ini sudah tercatat.
     *
     * @throws BusinessRuleException
     */
    private function pastikanNikBelumDipakai(string $nik, ?int $kecualiPendaftaranId = null): void
    {
        if (Murid::query()->where('nik', $nik)->exists()) {
            throw new BusinessRuleException('Anak dengan NIK ini sudah terdaftar sebagai murid. Wali yang sudah punya akun bisa menambahkannya lewat menu Tambah Anak; kalau belum, minta kartu akun ke sekolah.');
        }

        $pendaftaranLain = Pendaftaran::query()->where('nik', $nik)->whereNotIn('status', self::STATUS_SELESAI)
            ->when($kecualiPendaftaranId !== null, fn (Builder $query) => $query->whereKeyNot($kecualiPendaftaranId));

        if ($pendaftaranLain->exists()) {
            throw new BusinessRuleException('Anak dengan NIK ini sudah punya pendaftaran PPDB yang sedang diproses atau sudah diterima.');
        }
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
     * Pendaftar tanpa login belum punya akun sampai diterima, jadi verifikasi dan penolakannya tidak dikirimi
     * notifikasi; statusnya dicek lewat `GET /public/pendaftaran/status`.
     *
     * @param  array<string, mixed>  $properti
     */
    private function selesaikan(Pendaftaran $pendaftaran, User $kepalaSekolah, string $event, string $pesanLog, array $properti = []): Pendaftaran
    {
        activity('ppdb')->causedBy($kepalaSekolah)->performedOn($pendaftaran)->event($event)->withProperties($properti)->log($pesanLog);

        $pendaftaran->load(['waliMurid.user', 'murid.kelas']);
        $pendaftaran->waliMurid?->user->notify(new PendaftaranDiprosesNotification($pendaftaran));

        return $pendaftaran;
    }

    /**
     * Pendaftaran dengan kode dan tanggal lahir anak yang cocok, untuk cek status tanpa login.
     */
    public function cariUntukPublik(string $kode, Carbon $tanggalLahir): ?Pendaftaran
    {
        return Pendaftaran::query()->with('tahunAjaran')->where('kode', $kode)->whereDate('tanggal_lahir', $tanggalLahir)->first();
    }

    private function tahunAjaranTujuan(): ?TahunAjaran
    {
        $id = $this->pengaturan->nilai('ppdb.tahun_ajaran_id');

        return is_numeric($id) ? TahunAjaran::query()->find((int) $id) : null;
    }

    private function terpakai(TahunAjaran $tahunAjaran): int
    {
        return Pendaftaran::query()->where('tahun_ajaran_id', $tahunAjaran->id)->whereNotIn('status', self::STATUS_SELESAI)->count();
    }

    private function tanggal(string $kunci): ?Carbon
    {
        $nilai = $this->pengaturan->nilai($kunci);

        return is_string($nilai) && $nilai !== '' ? Carbon::parse($nilai)->startOfDay() : null;
    }
}
