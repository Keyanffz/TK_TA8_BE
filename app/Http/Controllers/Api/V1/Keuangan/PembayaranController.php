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
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PembayaranController extends Controller
{
    private const RELASI = ['tagihan.jenisTagihan', 'tagihan.murid.kelasAktif', 'pembayar', 'verifikator'];

    public function __construct(private readonly PembayaranService $pembayaranService) {}

    /**
     * Membayar tagihan. Wali murid mengunggah bukti transfer (`multipart/form-data`: `bukti`, `tanggal_bayar`,
     * `bank_pengirim`, `nama_pengirim`) dan pembayaran menunggu verifikasi. Petugas keuangan mencatat pembayaran
     * yang langsung diterima: `metode: tunai`, atau `metode: transfer` dengan `bukti`, `bank_pengirim`, dan
     * `nama_pengirim` opsional.
     */
    public function bayar(BayarTagihanRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $tagihan = Tagihan::query()->findOrFail($id);
        Gate::authorize('bayar', $tagihan);

        if ($request->dariWali()) {
            $bukti = $request->bukti() ?? throw new LogicException('Bukti transfer wali sudah divalidasi wajib ada.');
            $pembayaran = $this->pembayaranService->unggahBukti($tagihan, $request->dataPembayaran(), $bukti, $user);
            $pesan = 'Bukti transfer terkirim dan menunggu verifikasi petugas keuangan.';
        } else {
            $metode = $request->metode();
            $pembayaran = $this->pembayaranService->catatOlehPetugas($tagihan, $metode, $request->dataPembayaran(), $request->bukti(), $user);
            $pesan = "Pembayaran {$metode->value} {$pembayaran->kode} tercatat dan tagihan lunas.";
        }

        return ApiResponse::success(new PembayaranResource($pembayaran->load(self::RELASI)), $pesan, status: 201);
    }

    /**
     * Daftar pembayaran. Petugas keuangan melihat semua pembayaran, wali murid pembayaran tagihan anaknya.
     *
     * Filter `filter[status]`, `filter[metode]`, `filter[tanggal]` (tanggal bayar, YYYY-MM-DD). `search` mencari
     * kode pembayaran dan nama murid. Urutan `sort` = `tanggal_bayar` | `created_at` (bawaan `-created_at`).
     */
    public function index(DaftarPembayaranRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('viewAny', Pembayaran::class);

        $kata = $request->kataCari();
        $query = Pembayaran::query()->visibleTo($user)->with(self::RELASI)
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
     * Detail pembayaran. Guru tanpa izin keuangan ditolak 403 untuk pembayaran murid kelasnya.
     *
     * @throws AuthorizationException
     */
    public function show(int $id): JsonResponse
    {
        $pembayaran = $this->cariYangBolehDilihat($id);

        return ApiResponse::success(new PembayaranResource($pembayaran->load(self::RELASI)));
    }

    /**
     * Menerima pembayaran yang menunggu verifikasi; tagihan menjadi lunas dan wali diberi tahu.
     */
    public function terima(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $pembayaran = $this->pembayaranService->terima(Pembayaran::query()->findOrFail($id), $user);

        return ApiResponse::success(new PembayaranResource($pembayaran->load(self::RELASI)), "Pembayaran {$pembayaran->kode} diterima.");
    }

    /**
     * Menolak bukti transfer beserta alasannya. Tagihan kembali belum dibayar (atau terlambat jika sudah
     * lewat jatuh tempo) dan wali diberi tahu.
     */
    public function tolak(AlasanRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $pembayaran = $this->pembayaranService->tolak(Pembayaran::query()->findOrFail($id), $request->alasan(), $user);

        return ApiResponse::success(new PembayaranResource($pembayaran->load(self::RELASI)), "Pembayaran {$pembayaran->kode} ditolak.");
    }

    /**
     * File bukti transfer. Pembayaran tunai atau transfer tanpa bukti dibalas 404. Guru tanpa izin keuangan
     * ditolak 403 untuk pembayaran murid kelasnya.
     *
     * @throws AuthorizationException
     */
    #[Response(200, 'Gambar bukti transfer (JPEG)', mediaType: 'image/jpeg', type: 'string', format: 'binary')]
    public function bukti(int $id, MediaService $media): StreamedResponse
    {
        $pembayaran = $this->cariYangBolehDilihat($id);

        return ($pembayaran->bukti_path === null ? null : $media->responsPrivat($pembayaran->bukti_path)) ?? abort(404);
    }

    /**
     * Kwitansi PDF, hanya untuk pembayaran yang sudah diterima. Guru tanpa izin keuangan ditolak 403 untuk
     * pembayaran murid kelasnya.
     *
     * @throws AuthorizationException
     */
    #[Response(200, 'Kwitansi PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    public function kwitansi(int $id, KwitansiService $kwitansi): HttpResponse
    {
        $pembayaran = $this->cariYangBolehDilihat($id);

        return $kwitansi->buat($pembayaran)->download($kwitansi->namaFile($pembayaran));
    }

    private function cariYangBolehDilihat(int $id): Pembayaran
    {
        $pembayaran = Pembayaran::query()->findOrFail($id);
        Gate::authorize('view', $pembayaran);

        return $pembayaran;
    }
}
