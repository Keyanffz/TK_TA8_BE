<?php

namespace App\Services;

use App\Enums\PeriodeTagihan;
use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Enums\StatusPembayaran;
use App\Enums\StatusTagihan;
use App\Enums\TipeKeringanan;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\PeriodeDiLuarTahunAjaranException;
use App\Models\JenisTagihan;
use App\Models\KelasMurid;
use App\Models\Keringanan;
use App\Models\Murid;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Notifications\TagihanBaruNotification;
use App\Support\NomorUrut;
use App\Support\Rupiah;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Pembuatan tagihan (B6.1). Kode `INV-YYYYMM-XXXXX` berurutan per bulan: bulan periode untuk tagihan
 * bulanan, bulan pembuatan untuk tagihan sekali bayar.
 */
class TagihanService
{
    public const DIGIT_KODE = 5;

    private const TANGGAL_JATUH_TEMPO_BAWAAN = 10;

    /** Batas yang sama dengan validasi `keuangan.tanggal_jatuh_tempo`, supaya tanggalnya ada di setiap bulan. */
    private const TANGGAL_JATUH_TEMPO_MAKSIMAL = 28;

    public function __construct(private readonly PengaturanService $pengaturan) {}

    /**
     * Persen dibulatkan ke bawah; potongan nominal tidak melebihi nominal tagihan.
     */
    public static function potongan(int $nominal, ?Keringanan $keringanan): int
    {
        return match ($keringanan?->tipe) {
            null => 0,
            TipeKeringanan::Persen => intdiv($nominal * $keringanan->nilai, 100),
            TipeKeringanan::Nominal => min($keringanan->nilai, $nominal),
        };
    }

    public static function awalanKode(Carbon $bulan): string
    {
        return 'INV-'.$bulan->format('Ym').'-';
    }

    /**
     * Tagihan bulanan untuk semua murid aktif yang punya kelas di tahun ajaran aktif, dari setiap jenis
     * tagihan bulanan yang aktif dan sesuai tingkat kelasnya (A6). Idempoten: murid yang sudah punya
     * tagihan jenis itu di periode yang sama dilewati, termasuk yang dibatalkan (pembatalan dihormati;
     * tagihannya dipulihkan lewat `aktifkan()`), dan unique index menjadi penjaga terakhir.
     * `$pelaku` null berarti dijalankan scheduler; `dibuat_oleh` tagihan ikut null (sistem).
     *
     * @return array{dibuat: int, dilewati: int}
     *
     * @throws PeriodeDiLuarTahunAjaranException
     */
    public function generateBulanan(Carbon $periode, ?User $pelaku = null, bool $simulasi = false): array
    {
        $periode = $periode->copy()->startOfMonth();
        $tahunAjaran = $this->tahunAjaranUntuk($periode);

        ['dibuat' => $dibuat, 'dilewati' => $dilewati] = DB::transaction(
            fn (): array => $this->buatTagihanBulanan($periode, $tahunAjaran, $pelaku, $simulasi),
        );

        if (! $simulasi) {
            $this->beriTahuWali($dibuat);
            activity('tagihan')->causedBy($pelaku)->event('generate')
                ->withProperties(['periode' => $periode->format('Y-m'), 'dibuat' => $dibuat->count(), 'dilewati' => $dilewati])
                ->log("Generate tagihan bulanan {$periode->translatedFormat('F Y')}: {$dibuat->count()} dibuat, {$dilewati} dilewati");
        }

        return ['dibuat' => $dibuat->count(), 'dilewati' => $dilewati];
    }

    /**
     * Tagihan sekali bayar (uang pangkal, seragam) untuk murid tertentu. Murid yang sudah punya tagihan jenis
     * itu (selain yang dibatalkan), tidak aktif, atau tingkat kelasnya tidak sesuai dilewati. Potongan
     * memakai keringanan yang berlaku pada tanggal pembuatan.
     *
     * @param  list<int>  $muridIds
     * @return array{dibuat: int, dilewati: int}
     *
     * @throws BusinessRuleException
     */
    public function buatSekali(JenisTagihan $jenis, array $muridIds, Carbon $jatuhTempo, User $pembuat): array
    {
        if ($jenis->periode !== PeriodeTagihan::Sekali) {
            throw new BusinessRuleException("{$jenis->nama} adalah tagihan bulanan dan dibuat lewat generate tagihan bulanan, bukan tagihan sekali bayar.");
        }
        if (! $jenis->is_aktif) {
            throw new BusinessRuleException("Jenis tagihan {$jenis->nama} sedang nonaktif. Aktifkan terlebih dahulu.");
        }

        $hariIni = now()->startOfDay();
        $murid = Murid::query()->whereKey($muridIds)->with('kelasAktif')->get();

        $dibuat = DB::transaction(function () use ($jenis, $murid, $jatuhTempo, $pembuat, $hariIni): Collection {
            $nomor = NomorUrut::berikutnya(Tagihan::query(), 'kode', self::awalanKode($hariIni), self::DIGIT_KODE);
            $sudahPunya = Tagihan::query()->where('jenis_tagihan_id', $jenis->id)->whereIn('murid_id', $murid->modelKeys())
                ->where('status', '!=', StatusTagihan::Dibatalkan)->pluck('murid_id')->flip();
            $keringanan = $this->keringananBerlaku($jenis, $hariIni, $hariIni);
            $dibuat = new Collection;

            foreach ($murid as $satu) {
                $tingkatSesuai = $jenis->tingkat === null || $satu->kelasAktif->first()?->tingkat === $jenis->tingkat;

                if ($satu->status === StatusMurid::Aktif && $tingkatSesuai && ! $sudahPunya->has($satu->id)) {
                    $dibuat->push($this->simpan($satu->id, $jenis, null, $jatuhTempo, $nomor++, $hariIni, $keringanan->get($satu->id), $pembuat));
                }
            }

            return $dibuat;
        });

        $this->beriTahuWali($dibuat);

        return ['dibuat' => $dibuat->count(), 'dilewati' => count($muridIds) - $dibuat->count()];
    }

    /**
     * Murid dengan penempatan aktif di kelas itu, untuk `POST /tagihan` dengan `kelas_id`.
     *
     * @return list<int>
     */
    public function muridDiKelas(int $kelasId): array
    {
        return KelasMurid::query()->where('kelas_id', $kelasId)->where('status', StatusKelasMurid::Aktif)->pluck('murid_id')->all();
    }

    /**
     * @throws BusinessRuleException
     */
    public function batalkan(Tagihan $tagihan, string $alasan, User $pelaku): Tagihan
    {
        $tagihan = DB::transaction(function () use ($tagihan, $alasan): Tagihan {
            $tagihan = Tagihan::query()->lockForUpdate()->findOrFail($tagihan->id);
            $this->pastikanBisaDiubah($tagihan, 'dibatalkan');

            $tagihan->update(['status' => StatusTagihan::Dibatalkan, 'catatan' => $alasan]);

            return $tagihan;
        });

        activity('tagihan')->causedBy($pelaku)->performedOn($tagihan)->event('dibatalkan')
            ->withProperties(['alasan' => $alasan])
            ->log("Membatalkan tagihan {$tagihan->kode}");

        return $tagihan;
    }

    /**
     * Mengubah jatuh tempo, potongan, atau catatan tagihan yang belum dibayar; `total` dihitung ulang dari
     * nominal. Jatuh tempo baru tidak boleh sudah lewat. Tagihan terlambat yang jatuh temponya dimundurkan
     * kembali `belum_bayar`; potongan sebesar nominal membuat tagihan langsung lunas, sama seperti keringanan
     * penuh saat generate.
     *
     * @param  array{jatuh_tempo?: string, potongan?: int, catatan?: string|null}  $data
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function perbarui(Tagihan $tagihan, array $data, User $pelaku): Tagihan
    {
        [$tagihan, $sebelum] = DB::transaction(function () use ($tagihan, $data): array {
            $tagihan = Tagihan::query()->lockForUpdate()->findOrFail($tagihan->id);
            $this->pastikanBisaDiubah($tagihan);
            $sebelum = $tagihan->only(['jatuh_tempo', 'potongan', 'total', 'status']);

            $potongan = $data['potongan'] ?? $tagihan->potongan;
            if ($potongan > $tagihan->nominal) {
                throw ValidationException::withMessages(['potongan' => 'Potongan tidak boleh melebihi nominal tagihan ('.Rupiah::format($tagihan->nominal).').']);
            }

            $jatuhTempo = isset($data['jatuh_tempo']) ? Carbon::parse($data['jatuh_tempo']) : $tagihan->jatuh_tempo;
            if (! $jatuhTempo->isSameDay($tagihan->jatuh_tempo) && $jatuhTempo->lt(today())) {
                throw ValidationException::withMessages(['jatuh_tempo' => 'Jatuh tempo baru tidak boleh sebelum hari ini.']);
            }

            $total = $tagihan->nominal - $potongan;
            $tagihan->fill([
                'jatuh_tempo' => $jatuhTempo->toDateString(),
                'potongan' => $potongan,
                'total' => $total,
                'catatan' => array_key_exists('catatan', $data) ? $data['catatan'] : $tagihan->catatan,
                'status' => match (true) {
                    $total === 0 => StatusTagihan::Lunas,
                    $tagihan->status === StatusTagihan::Terlambat && $jatuhTempo->gte(today()) => StatusTagihan::BelumBayar,
                    default => $tagihan->status,
                },
                'lunas_at' => $total === 0 ? now() : null,
            ])->save();

            return [$tagihan, $sebelum];
        });

        activity('tagihan')->causedBy($pelaku)->performedOn($tagihan)->event('diubah')
            ->withProperties([
                'sebelum' => [...$sebelum, 'jatuh_tempo' => $sebelum['jatuh_tempo']->toDateString(), 'status' => $sebelum['status']->value],
                'sesudah' => ['jatuh_tempo' => $tagihan->jatuh_tempo->toDateString(), 'potongan' => $tagihan->potongan, 'total' => $tagihan->total, 'status' => $tagihan->status->value],
            ])
            ->log("Mengubah tagihan {$tagihan->kode}");

        return $tagihan;
    }

    /**
     * Mengembalikan tagihan yang dibatalkan menjadi `belum_bayar`, atau `terlambat` kalau jatuh temponya sudah
     * lewat. Ditolak kalau murid sudah punya tagihan aktif lain untuk jenis dan periode yang sama (untuk tagihan
     * sekali bayar, tagihan pengganti bisa sudah dibuat lewat `POST /tagihan`). Wali tidak diberi notifikasi.
     *
     * @throws BusinessRuleException
     */
    public function aktifkan(Tagihan $tagihan, User $kepalaSekolah): Tagihan
    {
        $tagihan = DB::transaction(function () use ($tagihan): Tagihan {
            $tagihan = Tagihan::query()->with('jenisTagihan')->lockForUpdate()->findOrFail($tagihan->id);

            if ($tagihan->status !== StatusTagihan::Dibatalkan) {
                throw new BusinessRuleException("Tagihan berstatus {$tagihan->status->label()} tidak perlu diaktifkan. Hanya tagihan yang dibatalkan yang bisa diaktifkan kembali.");
            }

            $pengganti = Tagihan::query()->whereKeyNot($tagihan->id)
                ->where('murid_id', $tagihan->murid_id)
                ->where('jenis_tagihan_id', $tagihan->jenis_tagihan_id)
                ->when($tagihan->periode === null, fn (Builder $query) => $query->whereNull('periode'), fn (Builder $query) => $query->whereDate('periode', $tagihan->periode))
                ->where('status', '!=', StatusTagihan::Dibatalkan)
                ->value('kode');
            if ($pengganti !== null) {
                throw new BusinessRuleException("Murid ini sudah punya tagihan {$tagihan->label()} lain yang aktif ({$pengganti}). Batalkan tagihan itu dulu kalau tagihan ini yang ingin dipakai.");
            }

            $tagihan->update(['status' => $tagihan->jatuh_tempo->lt(today()) ? StatusTagihan::Terlambat : StatusTagihan::BelumBayar]);

            return $tagihan;
        });

        activity('tagihan')->causedBy($kepalaSekolah)->performedOn($tagihan)->event('diaktifkan')
            ->withProperties(['status' => $tagihan->status->value])
            ->log("Mengaktifkan kembali tagihan {$tagihan->kode}");

        return $tagihan;
    }

    /**
     * @throws PeriodeDiLuarTahunAjaranException
     */
    private function tahunAjaranUntuk(Carbon $periode): TahunAjaran
    {
        $tahunAjaran = TahunAjaran::query()->aktif()->first()
            ?? throw new PeriodeDiLuarTahunAjaranException($periode, null, 'Belum ada tahun ajaran aktif, jadi tagihan bulanan belum bisa dibuat.');

        $awal = $tahunAjaran->tanggal_mulai->copy()->startOfMonth();
        $akhir = $tahunAjaran->tanggal_selesai->copy()->startOfMonth();

        if ($periode->lt($awal) || $periode->gt($akhir)) {
            throw new PeriodeDiLuarTahunAjaranException($periode, $tahunAjaran->nama, "Periode {$periode->translatedFormat('F Y')} di luar tahun ajaran aktif {$tahunAjaran->nama} ({$awal->translatedFormat('F Y')} – {$akhir->translatedFormat('F Y')}).");
        }

        return $tahunAjaran;
    }

    /**
     * Tagihan yang sudah lunas, sudah dibatalkan, atau punya bukti transfer yang menunggu verifikasi tidak
     * bisa diubah maupun dibatalkan.
     *
     * @throws BusinessRuleException
     */
    private function pastikanBisaDiubah(Tagihan $tagihan, string $aksi = 'diubah'): void
    {
        $alasanTolak = match (true) {
            $tagihan->status === StatusTagihan::Dibatalkan => 'Tagihan ini sudah dibatalkan.',
            $tagihan->status === StatusTagihan::Lunas => "Tagihan yang sudah lunas tidak bisa {$aksi}.",
            $tagihan->pembayaran()->where('status', StatusPembayaran::Menunggu)->exists() => 'Masih ada bukti transfer yang menunggu verifikasi. Terima atau tolak pembayaran itu terlebih dahulu.',
            default => null,
        };

        if ($alasanTolak !== null) {
            throw new BusinessRuleException($alasanTolak);
        }
    }

    /**
     * Kode dikunci lebih dulu (`NomorUrut`), baru tagihan yang sudah ada dibaca, supaya dua generate
     * bersamaan untuk periode yang sama tidak membuat tagihan ganda. Dalam simulasi tidak ada yang disimpan;
     * yang dihitung hanya tagihan yang akan dibuat.
     *
     * @return array{dibuat: Collection<int, Tagihan>, dilewati: int}
     */
    private function buatTagihanBulanan(Carbon $periode, TahunAjaran $tahunAjaran, ?User $pelaku, bool $simulasi): array
    {
        $jenisTagihan = $tahunAjaran->jenisTagihan()->where('periode', PeriodeTagihan::Bulanan)->where('is_aktif', true)->get();
        $penempatan = $this->penempatanAktif($tahunAjaran);
        $jatuhTempo = $periode->copy()->day($this->tanggalJatuhTempo());

        $nomor = NomorUrut::berikutnya(Tagihan::query(), 'kode', self::awalanKode($periode), self::DIGIT_KODE);
        $sudahAda = Tagihan::query()->whereDate('periode', $periode)->whereIn('jenis_tagihan_id', $jenisTagihan->modelKeys())
            ->get(['murid_id', 'jenis_tagihan_id'])
            ->mapWithKeys(fn (Tagihan $tagihan): array => ["{$tagihan->jenis_tagihan_id}-{$tagihan->murid_id}" => true]);
        $dibuat = new Collection;
        $dilewati = 0;

        foreach ($jenisTagihan as $jenis) {
            $keringanan = $this->keringananBerlaku($jenis, $periode, $periode->copy()->endOfMonth());
            $sasaran = $penempatan->filter(fn (KelasMurid $satu): bool => $jenis->tingkat === null || $jenis->tingkat === $satu->kelas->tingkat);

            foreach ($sasaran as $satu) {
                if ($sudahAda->has("{$jenis->id}-{$satu->murid_id}")) {
                    $dilewati++;
                } else {
                    $dibuat->push($simulasi
                        ? new Tagihan(['murid_id' => $satu->murid_id])
                        : $this->simpan($satu->murid_id, $jenis, $periode, $jatuhTempo, $nomor++, $periode, $keringanan->get($satu->murid_id), $pelaku));
                }
            }
        }

        return ['dibuat' => $dibuat, 'dilewati' => $dilewati];
    }

    /**
     * @return Collection<int, KelasMurid>
     */
    private function penempatanAktif(TahunAjaran $tahunAjaran): Collection
    {
        return KelasMurid::query()->with('kelas')
            ->where('status', StatusKelasMurid::Aktif)
            ->whereHas('kelas', fn (Builder $kelas) => $kelas->where('tahun_ajaran_id', $tahunAjaran->id))
            ->whereHas('murid', fn (Builder $murid) => $murid->where('status', StatusMurid::Aktif))
            ->orderBy('murid_id')
            ->get();
    }

    private function tanggalJatuhTempo(): int
    {
        $tanggal = (int) $this->pengaturan->nilai('keuangan.tanggal_jatuh_tempo', self::TANGGAL_JATUH_TEMPO_BAWAAN);

        return max(1, min($tanggal, self::TANGGAL_JATUH_TEMPO_MAKSIMAL));
    }

    /**
     * Keringanan yang masa berlakunya bersinggungan dengan rentang tanggal, per murid. Kalau ada lebih dari
     * satu (misalnya satu berakhir dan yang lain mulai di bulan yang sama), yang mulai paling akhir dipakai.
     *
     * @return Collection<int, Keringanan>
     */
    private function keringananBerlaku(JenisTagihan $jenis, Carbon $dari, Carbon $sampai): Collection
    {
        return Keringanan::query()
            ->where('jenis_tagihan_id', $jenis->id)
            ->whereDate('berlaku_mulai', '<=', $sampai)
            ->where(fn (Builder $query) => $query->whereNull('berlaku_sampai')->orWhereDate('berlaku_sampai', '>=', $dari))
            ->orderBy('berlaku_mulai')
            ->get()
            ->keyBy('murid_id');
    }

    private function simpan(int $muridId, JenisTagihan $jenis, ?Carbon $periode, Carbon $jatuhTempo, int $nomor, Carbon $bulanKode, ?Keringanan $keringanan, ?User $pembuat = null): Tagihan
    {
        $potongan = self::potongan($jenis->nominal, $keringanan);
        $total = $jenis->nominal - $potongan;

        return Tagihan::query()->create([
            'kode' => NomorUrut::format(self::awalanKode($bulanKode), $nomor, self::DIGIT_KODE),
            'murid_id' => $muridId,
            'jenis_tagihan_id' => $jenis->id,
            'tahun_ajaran_id' => $jenis->tahun_ajaran_id,
            'periode' => $periode?->toDateString(),
            'nominal' => $jenis->nominal,
            'potongan' => $potongan,
            'total' => $total,
            'jatuh_tempo' => $jatuhTempo->toDateString(),
            'status' => $total === 0 ? StatusTagihan::Lunas : StatusTagihan::BelumBayar,
            'lunas_at' => $total === 0 ? now() : null,
            'dibuat_oleh' => $pembuat?->id,
        ]);
    }

    /**
     * Tagihan yang langsung lunas karena keringanan 100% tidak perlu diberitahukan.
     *
     * @param  Collection<int, Tagihan>  $tagihan
     */
    private function beriTahuWali(Collection $tagihan): void
    {
        Tagihan::query()->whereKey($tagihan->modelKeys())->where('total', '>', 0)
            ->with(['jenisTagihan', 'murid.waliMurid.user'])
            ->get()
            ->each(fn (Tagihan $satu) => Notification::send($satu->murid->waliMurid->pluck('user'), new TagihanBaruNotification($satu)));
    }
}
