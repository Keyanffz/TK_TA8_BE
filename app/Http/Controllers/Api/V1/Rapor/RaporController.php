<?php

namespace App\Http\Controllers\Api\V1\Rapor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rapor\BuatRaporRequest;
use App\Http\Requests\Rapor\CatatanRevisiRequest;
use App\Http\Requests\Rapor\DaftarRaporRequest;
use App\Http\Requests\Rapor\FotoRaporRequest;
use App\Http\Requests\Rapor\IsiRaporRequest;
use App\Http\Resources\RaporResource;
use App\Models\Rapor;
use App\Models\User;
use App\Services\RaporPdfService;
use App\Services\RaporService;
use App\Support\ApiResponse;
use App\Support\Jangkauan;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RaporController extends Controller
{
    private const RELASI = ['murid', 'kelas', 'tahunAjaran', 'pembuat.user'];

    private const RELASI_DETAIL = [...self::RELASI, 'detail.elemenPenilaian'];

    public function __construct(private readonly RaporService $raporService) {}

    /**
     * Daftar rapor. Kepala Sekolah melihat semua, guru rapor kelas yang dia ampu di tahun ajaran aktif, wali murid
     * rapor anaknya yang sudah terbit.
     *
     * Filter `filter[kelas_id]`, `filter[semester]`, `filter[status]`, `filter[tahun_ajaran_id]`, `filter[murid_id]`.
     * `search` mencari nama dan NIS murid. Urutan `sort` = `updated_at` | `diajukan_at` | `created_at`
     * (bawaan `-updated_at`).
     */
    public function index(DaftarRaporRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $kata = $request->kataCari();
        $query = Rapor::query()->visibleTo($user)->with(self::RELASI)
            ->when($kata !== null, fn (Builder $query) => $query->whereHas('murid', fn (Builder $murid) => $murid->cari($kata)));

        $rapor = QueryBuilder::for($query, $request)
            ->allowedFilters(
                AllowedFilter::exact('kelas_id'),
                AllowedFilter::exact('semester'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('tahun_ajaran_id'),
                AllowedFilter::exact('murid_id'),
            )
            ->allowedSorts('updated_at', 'diajukan_at', 'created_at')
            ->defaultSort('-updated_at', '-id')
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(RaporResource::collection($rapor));
    }

    /**
     * Detail rapor beserta deskripsi dan foto tiap elemen.
     */
    public function show(int $id): JsonResponse
    {
        $rapor = Rapor::query()->findOrFail($id);
        Jangkauan::pastikanTerlihat($rapor);

        return ApiResponse::success(new RaporResource($rapor->load(self::RELASI_DETAIL)));
    }

    /**
     * Membuat draft rapor untuk murid di kelas yang diampu, dengan baris kosong untuk setiap elemen penilaian
     * aktif. Satu murid satu rapor per semester per tahun ajaran.
     */
    public function store(BuatRaporRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $rapor = $this->raporService->buat($request->murid(), $request->integer('semester'), $user);

        return ApiResponse::success(new RaporResource($rapor->load(self::RELASI_DETAIL)), "Draft rapor semester {$rapor->semester} {$rapor->murid->nama_lengkap} dibuat.", status: 201);
    }

    /**
     * Mengisi rapor (hanya guru pembuat, saat status draft atau revisi). Elemen yang tidak dikirim di `detail`
     * tidak berubah.
     */
    public function update(IsiRaporRequest $request, int $id): JsonResponse
    {
        $rapor = $this->cariYangBolehDiubah($id);
        $rapor = $this->raporService->isi($rapor, $request->dataRapor(), $request->deskripsiPerElemen());

        return ApiResponse::success(new RaporResource($rapor->load(self::RELASI_DETAIL)), 'Rapor tersimpan.');
    }

    /**
     * Mengunggah foto untuk satu elemen rapor (menggantikan foto sebelumnya). Hanya guru pembuat, saat status
     * draft atau revisi.
     */
    public function foto(FotoRaporRequest $request, int $id, int $detail_id): JsonResponse
    {
        $rapor = $this->cariYangBolehDiubah($id);
        $detail = $rapor->detail()->findOrFail($detail_id);
        $this->raporService->simpanFoto($rapor, $detail, $request->foto());

        return ApiResponse::success(new RaporResource($rapor->load(self::RELASI_DETAIL)), 'Foto tersimpan.');
    }

    /**
     * Mengajukan rapor ke Kepala Sekolah. Semua elemen harus sudah berdeskripsi.
     */
    public function ajukan(int $id): JsonResponse
    {
        $rapor = $this->raporService->ajukan($this->cariYangBolehDiubah($id));

        return ApiResponse::success(new RaporResource($rapor->load(self::RELASI_DETAIL)), 'Rapor diajukan ke Kepala Sekolah.');
    }

    /**
     * Menerbitkan rapor yang sudah diajukan; wali murid diberi tahu dan bisa mengunduh PDF-nya.
     */
    public function terbitkan(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $rapor = $this->raporService->terbitkan(Rapor::query()->findOrFail($id), $user);

        return ApiResponse::success(new RaporResource($rapor->load(self::RELASI_DETAIL)), "Rapor {$rapor->murid->nama_lengkap} diterbitkan.");
    }

    /**
     * Mengembalikan rapor yang sudah diajukan ke guru untuk direvisi, dengan catatan.
     */
    public function revisi(CatatanRevisiRequest $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $rapor = $this->raporService->mintaRevisi(Rapor::query()->findOrFail($id), $request->catatan(), $user);

        return ApiResponse::success(new RaporResource($rapor->load(self::RELASI_DETAIL)), 'Rapor dikembalikan ke guru untuk direvisi.');
    }

    /**
     * Rapor PDF. Wali murid hanya bisa mengunduh rapor yang sudah terbit; rapor yang belum terbit diberi tanda
     * pratinjau.
     */
    #[Response(200, 'Rapor PDF', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    public function pdf(int $id, RaporPdfService $raporPdf): HttpResponse
    {
        $rapor = Rapor::query()->findOrFail($id);
        Jangkauan::pastikanTerlihat($rapor);

        return $raporPdf->buat($rapor)->download($raporPdf->namaFile($rapor));
    }

    private function cariYangBolehDiubah(int $id): Rapor
    {
        $rapor = Rapor::query()->findOrFail($id);
        Gate::authorize('ubah', $rapor);

        return $rapor;
    }
}
