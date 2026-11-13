<?php

namespace App\Http\Controllers\Api\V1\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Requests\AlasanRequest;
use App\Http\Requests\Pembayaran\BayarTagihanRequest;
use App\Http\Requests\Pembayaran\DaftarPembayaranRequest;
use App\Http\Resources\PembayaranResource;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\User;
use App\Services\KwitansiService;
use App\Services\MediaService;
use App\Services\PembayaranService;
use App\Support\ApiResponse;
use App\Support\Jangkauan;
use App\Support\Rupiah;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PembayaranController extends Controller
{
    public function __construct(private readonly PembayaranService $pembayaranService) {}

    /**
     * Mencatat pembayaran tunai yang diterima di sekolah (`jumlah`, `tanggal_bayar`). Untuk petugas keuangan,
     * dan wali kelas untuk murid kelasnya. Pembayaran langsung diterima; `jumlah` boleh sebagian dari tagihan
     * (cicilan) dan tidak boleh melebihi sisa. Tagihan lunas saat sisanya habis.
     */
    public function bayar(BayarTagihanRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $tagihan = Tagihan::query()->findOrFail($id);
        Gate::authorize('bayar', $tagihan);

        $pembayaran = $this->pembayaranService->catat($tagihan, $request->jumlah(), $request->tanggalBayar(), $user);
        $pembayaran->load($this->relasi());
        $sisa = $pembayaran->tagihan->sisa();
        $pesan = $sisa === 0
            ? "Pembayaran {$pembayaran->kode} tercatat dan tagihan lunas."
            : "Pembayaran {$pembayaran->kode} tercatat. Sisa tagihan ".Rupiah::format($sisa).'.';

        return ApiResponse::success(new PembayaranResource($pembayaran), $pesan, status: 201);
    }

    /**
     * Daftar pembayaran. Pemegang izin keuangan melihat semua pembayaran, wali murid pembayaran tagihan anaknya.
     *
     * Filter `filter[status]`, `filter[metode]`, `filter[tanggal]` (tanggal bayar, YYYY-MM-DD). `search` mencari
     * kode pembayaran dan nama murid. Urutan `sort` = `tanggal_bayar` | `created_at` (bawaan `-created_at`).
     */
    public function index(DaftarPembayaranRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('viewAny', Pembayaran::class);

        $kata = $request->kataCari();
        $query = Pembayaran::query()->visibleTo($user)->with($this->relasi())
            ->when($kata !== null, fn (Builder $query) => $query->where(fn (Builder $cari) => $cari
                ->where('kode', 'like', "%{$kata}%")
                ->orWhereHas('tagihan.murid', fn (Builder $murid) => $murid->cari($kata))));

        $pembayaran = QueryBuilder::for($query, $request)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('metode'),
                AllowedFilter::callback('tanggal', fn (Builder $query, mixed $tanggal) => $query->whereDate('tanggal_bayar', (string) $tanggal)),
            )
            ->allowedSorts('tanggal_bayar', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(PembayaranResource::collection($pembayaran));
    }

    /**
     * Detail pembayaran. Guru tanpa izin keuangan hanya bisa membuka pembayaran murid kelasnya.
     */
    public function show(int $id): JsonResponse
    {
        $pembayaran = $this->cariYangTerlihat($id);

        return ApiResponse::success(new PembayaranResource($pembayaran->load($this->relasi())));
    }

    /**
     * Membatalkan pembayaran yang salah catat, dengan alasan. Hanya pembayaran yang diterima. Sisa tagihan
     * dihitung ulang: tagihan yang tadinya lunas kembali belum bayar, atau terlambat kalau sudah lewat jatuh
     * tempo. Setelah itu petugas bisa mencatat ulang jumlah yang benar.
     */
    public function batalkan(AlasanRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $pembayaran = $this->pembayaranService->batalkan(Pembayaran::query()->findOrFail($id), $request->alasan(), $user);

        return ApiResponse::success(new PembayaranResource($pembayaran->load($this->relasi())), "Pembayaran {$pembayaran->kode} dibatalkan.");
    }

    /**
     * File bukti transfer dari riwayat lama. Pembayaran tanpa bukti (semua pembayaran tunai) dibalas 404. Guru
     * tanpa izin keuangan ditolak 403 untuk pembayaran murid kelasnya.
     *
     * @throws AuthorizationException
     */
    #[Response(200, 'Gambar bukti transfer (JPEG)', mediaType: 'image/jpeg', type: 'string', format: 'binary')]
    public function bukti(int $id, MediaService $media): StreamedResponse
    {
        $pembayaran = Pembayaran::query()->findOrFail($id);
        Gate::authorize('lihatBukti', $pembayaran);

        return ($pembayaran->bukti_path === null ? null : $media->responsPrivat($pembayaran->bukti_path)) ?? abort(404);
    }

    /**
     * Kwitansi PDF satu pembayaran, memuat total tagihan, yang sudah dibayar sebelumnya, dan sisa setelah
     * pembayaran ini. Hanya untuk pembayaran yang diterima.
     */
    #[Response(200, 'Kwitansi PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    public function kwitansi(int $id, KwitansiService $kwitansi): HttpResponse
    {
        $pembayaran = $this->cariYangTerlihat($id);

        return $kwitansi->buat($pembayaran)->download($kwitansi->namaFile($pembayaran));
    }

    private function cariYangTerlihat(int $id): Pembayaran
    {
        $pembayaran = Pembayaran::query()->findOrFail($id);
        Jangkauan::pastikanTerlihat($pembayaran);

        return $pembayaran;
    }

    /**
     * Relasi untuk `PembayaranResource`; tagihannya dimuat beserta jumlah yang sudah dibayar.
     *
     * @return array<int|string, mixed>
     */
    private function relasi(): array
    {
        return [
            'tagihan' => self::denganTerbayar(...),
            'tagihan.jenisTagihan', 'tagihan.murid.kelasAktif', 'pembayar', 'verifikator', 'pembatal',
        ];
    }

    /**
     * @param  BelongsTo<Tagihan, Pembayaran>  $tagihan
     */
    private static function denganTerbayar(BelongsTo $tagihan): void
    {
        $tagihan->denganTerbayar();
    }
}
