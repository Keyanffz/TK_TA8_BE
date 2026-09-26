<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Enums\StatusKelasMurid;
use App\Enums\StatusMurid;
use App\Enums\StatusPembayaran;
use App\Enums\StatusPendaftaran;
use App\Enums\StatusRapor;
use App\Enums\StatusTagihan;
use App\Http\Resources\AgendaResource;
use App\Http\Resources\AnakWaliResource;
use App\Http\Resources\KegiatanKelasResource;
use App\Http\Resources\PengumumanResource;
use App\Http\Resources\RaporResource;
use App\Http\Resources\TagihanResource;
use App\Models\Agenda;
use App\Models\KegiatanKelas;
use App\Models\Kelas;
use App\Models\KelasMurid;
use App\Models\Murid;
use App\Models\Pembayaran;
use App\Models\Pendaftaran;
use App\Models\Pengumuman;
use App\Models\Rapor;
use App\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Isi beranda `GET /dashboard` per role (A7, B6.13). Daftar "terbaru" dan "mendatang" berisi paling banyak
 * lima item. Keuangan bulan ini memakai aturan laporan keuangan: tagihan menurut bulan jatuh tempo, tanpa
 * yang dibatalkan.
 */
class DashboardService
{
    private const JUMLAH_DAFTAR = 5;

    private const BULAN_GRAFIK = 12;

    private const STATUS_TAGIHAN_AKTIF = [StatusTagihan::BelumBayar, StatusTagihan::MenungguVerifikasi, StatusTagihan::Terlambat];

    public function __construct(private readonly LaporanKeuanganService $laporan) {}

    /**
     * @return array{
     *     statistik: array{murid_aktif: int, guru_aktif: int, kelas: int, wali_murid: int},
     *     keuangan_bulan_ini: array{total_tagihan: int, terbayar: int, belum_terbayar: int, persen_lunas: float},
     *     grafik_pemasukan: list<array{bulan: string, total: int}>,
     *     tertunda: array{guru_pending: int, pembayaran_menunggu: int, rapor_diajukan: int, pendaftaran_baru: int},
     *     pengumuman_terbaru: AnonymousResourceCollection,
     *     agenda_mendatang: AnonymousResourceCollection
     * }
     */
    public function kepalaSekolah(User $user): array
    {
        $bulanIni = now()->startOfMonth();
        $keuangan = $this->laporan->ringkasan($bulanIni, $bulanIni->copy()->endOfMonth(), null)['ringkasan'];
        $grafik = $this->laporan->ringkasan($bulanIni->copy()->subMonths(self::BULAN_GRAFIK - 1), $bulanIni->copy()->endOfMonth(), null)['per_bulan'];

        return [
            'statistik' => [
                'murid_aktif' => Murid::query()->where('status', StatusMurid::Aktif)->count(),
                // Profil guru Kepala Sekolah tidak terhitung karena akunnya ber-role super_admin (A7).
                'guru_aktif' => User::query()->where('role', Role::Guru)->where('status', StatusAkun::Aktif)->count(),
                'kelas' => Kelas::query()->whereHas('tahunAjaran', fn (Builder $tahunAjaran) => $tahunAjaran->aktif())->count(),
                'wali_murid' => User::query()->where('role', Role::WaliMurid)->where('status', StatusAkun::Aktif)->count(),
            ],
            'keuangan_bulan_ini' => [
                'total_tagihan' => $keuangan['total_tagihan'],
                'terbayar' => $keuangan['terbayar'],
                'belum_terbayar' => $keuangan['belum_terbayar'],
                'persen_lunas' => $keuangan['persen_lunas'],
            ],
            /** @var list<array{bulan: string, total: int}> */
            'grafik_pemasukan' => array_map(fn (array $bulan): array => ['bulan' => $bulan['bulan'], 'total' => $bulan['pemasukan']], $grafik),
            'tertunda' => [
                'guru_pending' => User::query()->where('role', Role::Guru)->where('status', StatusAkun::Pending)->count(),
                'pembayaran_menunggu' => Pembayaran::query()->where('status', StatusPembayaran::Menunggu)->count(),
                'rapor_diajukan' => Rapor::query()->where('status', StatusRapor::Diajukan)->count(),
                'pendaftaran_baru' => Pendaftaran::query()->where('status', StatusPendaftaran::Diajukan)->count(),
            ],
            'pengumuman_terbaru' => $this->pengumumanTerbaru($user),
            'agenda_mendatang' => $this->agendaMendatang(),
        ];
    }

    /**
     * `progres_rapor.total` = jumlah murid aktif di kelas yang diampu; status lain menghitung rapor semester
     * aktif tahun ajaran aktif, jadi murid yang belum dibuatkan rapor = total dikurangi jumlah keempatnya.
     * `keuangan_kelas` menghitung tagihan murid kelasnya yang jatuh tempo bulan ini.
     *
     * @return array{
     *     kelas_saya: list<array{id: int, nama: string, jumlah_murid: int}>,
     *     progres_rapor: array{total: int, draft: int, diajukan: int, revisi: int, terbit: int},
     *     kegiatan_terbaru: AnonymousResourceCollection,
     *     pengumuman_terbaru: AnonymousResourceCollection,
     *     agenda_mendatang: AnonymousResourceCollection,
     *     keuangan_kelas: array{lunas: int, belum: int},
     *     pembayaran_menunggu: int|null
     * }
     */
    public function guru(User $user): array
    {
        $kelas = Kelas::query()->diampuOleh($user)->withCount('muridAktif')->orderBy('nama')->get();
        $kelasIds = $kelas->modelKeys();
        $semester = TahunAjaran::query()->aktif()->value('semester_aktif');
        $rapor = Rapor::query()->whereIn('kelas_id', $kelasIds)->where('semester', $semester)
            ->toBase()->selectRaw('status, count(*) as jumlah')->groupBy('status')->pluck('jumlah', 'status');
        $tagihanBulanIni = Tagihan::query()->where('status', '!=', StatusTagihan::Dibatalkan)
            ->whereBetween('jatuh_tempo', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->whereIn('murid_id', KelasMurid::query()->whereIn('kelas_id', $kelasIds)->where('status', StatusKelasMurid::Aktif)->select('murid_id'))
            ->pluck('status');
        $lunas = $tagihanBulanIni->filter(fn (StatusTagihan $status): bool => $status === StatusTagihan::Lunas)->count();

        return [
            /** @var list<array{id: int, nama: string, jumlah_murid: int}> */
            'kelas_saya' => $kelas->map(fn (Kelas $satu): array => ['id' => $satu->id, 'nama' => $satu->nama, 'jumlah_murid' => (int) $satu->murid_aktif_count])->values()->all(),
            'progres_rapor' => [
                'total' => (int) $kelas->sum('murid_aktif_count'),
                'draft' => (int) ($rapor[StatusRapor::Draft->value] ?? 0),
                'diajukan' => (int) ($rapor[StatusRapor::Diajukan->value] ?? 0),
                'revisi' => (int) ($rapor[StatusRapor::Revisi->value] ?? 0),
                'terbit' => (int) ($rapor[StatusRapor::Terbit->value] ?? 0),
            ],
            'kegiatan_terbaru' => KegiatanKelasResource::collection(
                KegiatanKelas::query()->visibleTo($user)->with(['kelas', 'guru.user', 'foto'])->latest('tanggal')->latest('id')->limit(self::JUMLAH_DAFTAR)->get(),
            ),
            'pengumuman_terbaru' => $this->pengumumanTerbaru($user),
            'agenda_mendatang' => $this->agendaMendatang(),
            'keuangan_kelas' => ['lunas' => $lunas, 'belum' => $tagihanBulanIni->count() - $lunas],
            'pembayaran_menunggu' => $user->bisaKelolaKeuangan() ? Pembayaran::query()->where('status', StatusPembayaran::Menunggu)->count() : null,
        ];
    }

    /**
     * Beranda untuk satu anak: anak pilihan `murid_id` (harus anaknya sendiri), atau anak pertama menurut nama
     * panggilan. Wali yang belum punya anak tertaut mendapat `anak: null` dan daftar anak yang kosong.
     * `total_belum_bayar` tidak menghitung tagihan yang menunggu verifikasi.
     *
     * @return array{
     *     anak: AnakWaliResource|null,
     *     tagihan_aktif: AnonymousResourceCollection,
     *     total_belum_bayar: int,
     *     kegiatan_terbaru: AnonymousResourceCollection,
     *     pengumuman_terbaru: AnonymousResourceCollection,
     *     agenda_mendatang: AnonymousResourceCollection,
     *     rapor_terbaru: RaporResource|null
     * }
     */
    public function waliMurid(User $user, ?int $muridId): array
    {
        $anakQuery = $user->profilWaliMurid()->murid()->with('kelasAktif')->orderBy('nama_panggilan');
        $anak = $muridId === null ? $anakQuery->first() : $anakQuery->findOrFail($muridId);
        $anakId = $anak->id ?? 0;

        $tagihan = Tagihan::query()->where('murid_id', $anakId)->whereIn('status', self::STATUS_TAGIHAN_AKTIF)
            ->with(['jenisTagihan', 'murid.kelasAktif'])->orderBy('jatuh_tempo')->get();
        $rapor = Rapor::query()->where('murid_id', $anakId)->where('status', StatusRapor::Terbit)
            ->with(['murid', 'kelas', 'tahunAjaran', 'pembuat.user'])->latest('terbit_at')->first();

        return [
            'anak' => $anak === null ? null : new AnakWaliResource($anak),
            'tagihan_aktif' => TagihanResource::collection($tagihan),
            'total_belum_bayar' => (int) $tagihan->filter(fn (Tagihan $satu): bool => $satu->status !== StatusTagihan::MenungguVerifikasi)->sum('total'),
            'kegiatan_terbaru' => KegiatanKelasResource::collection(
                KegiatanKelas::query()->whereHas('kelas.murid', fn (Builder $murid) => $murid->whereKey($anakId))
                    ->with(['kelas', 'guru.user', 'foto'])->latest('tanggal')->latest('id')->limit(self::JUMLAH_DAFTAR)->get(),
            ),
            'pengumuman_terbaru' => $this->pengumumanTerbaru($user),
            'agenda_mendatang' => $this->agendaMendatang(),
            /** @var RaporResource|null */
            'rapor_terbaru' => $rapor === null ? null : new RaporResource($rapor),
        ];
    }

    private function pengumumanTerbaru(User $user): AnonymousResourceCollection
    {
        return PengumumanResource::collection(
            Pengumuman::query()->visibleTo($user)->terbit()->with(['penulis', 'kelas', 'murid'])
                ->orderByDesc('is_pinned')->latest('published_at')->latest('id')->limit(self::JUMLAH_DAFTAR)->get(),
        );
    }

    private function agendaMendatang(): AnonymousResourceCollection
    {
        return AgendaResource::collection(
            Agenda::query()->whereDate('tanggal_selesai', '>=', today())->orderBy('tanggal_mulai')->orderBy('id')->limit(self::JUMLAH_DAFTAR)->get(),
        );
    }
}
