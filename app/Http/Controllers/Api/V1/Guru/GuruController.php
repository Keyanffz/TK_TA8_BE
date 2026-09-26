<?php

namespace App\Http\Controllers\Api\V1\Guru;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guru\DaftarGuruRequest;
use App\Http\Requests\Guru\SimpanGuruRequest;
use App\Http\Requests\Guru\TolakGuruRequest;
use App\Http\Requests\UbahStatusAkunRequest;
use App\Http\Resources\GuruResource;
use App\Models\Guru;
use App\Models\User;
use App\Services\GuruService;
use App\Support\ApiResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class GuruController extends Controller
{
    public function __construct(private readonly GuruService $guruService) {}

    /**
     * Daftar guru (tanpa profil guru milik Kepala Sekolah).
     *
     * Filter `filter[status]` = `pending` | `aktif` | `ditolak` | `nonaktif`. Urutan `sort` = `nama` | `created_at`
     * (awali `-` untuk menurun). `search` mencari nama, email, NIP, dan NUPTK.
     */
    public function index(DaftarGuruRequest $request): JsonResponse
    {
        $urutNama = AllowedSort::callback('nama', fn (Builder $query, bool $menurun) => $query->orderBy(
            User::query()->select('name')->whereColumn('users.id', 'guru.user_id'),
            $menurun ? 'desc' : 'asc',
        ));

        $guru = QueryBuilder::for(Guru::query()->bukanKepalaSekolah()->with('user')->cari($request->kataCari()), $request)
            ->allowedFilters(AllowedFilter::callback('status', fn (Builder $query, mixed $status) => $query
                ->whereHas('user', fn (Builder $user) => $user->whereIn('status', (array) $status))))
            ->allowedSorts($urutNama, AllowedSort::field('created_at'))
            ->defaultSort($urutNama)
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(GuruResource::collection($guru));
    }

    /**
     * Detail guru, termasuk profil guru milik Kepala Sekolah.
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(new GuruResource($this->cariGuru($id)));
    }

    /**
     * Membuat akun guru yang langsung aktif. Kirim sebagai `multipart/form-data` jika menyertakan foto.
     *
     * `password_awal` hanya muncul di respons ini; sampaikan ke guru yang bersangkutan.
     */
    public function store(SimpanGuruRequest $request, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $hasil = $this->guruService->buat($request->validated(), $request->foto(), $kepalaSekolah);

        return ApiResponse::success(
            GuruResource::make($hasil['guru'])->denganPasswordAwal($hasil['password_awal']),
            'Akun guru dibuat. Sampaikan password awal ke guru; password ini hanya ditampilkan sekali.',
            status: 201,
        );
    }

    /**
     * Mengubah data guru, termasuk izin kelola keuangan dan tampil di landing page.
     */
    public function update(SimpanGuruRequest $request, int $id, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $guru = $this->guruService->perbarui($this->cariGuru($id), $request->validated(), $request->foto(), $kepalaSekolah);

        return ApiResponse::success(new GuruResource($guru), 'Data guru tersimpan.');
    }

    /**
     * Menyetujui pendaftaran guru. Guru mendapat email pemberitahuan.
     */
    public function setujui(int $id, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $guru = $this->guruService->setujui($this->cariGuru($id), $kepalaSekolah);

        return ApiResponse::success(new GuruResource($guru), "Pendaftaran {$guru->user->name} disetujui.");
    }

    /**
     * Menolak pendaftaran guru beserta alasannya. Guru mendapat email berisi alasan.
     */
    public function tolak(TolakGuruRequest $request, int $id, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $guru = $this->guruService->tolak($this->cariGuru($id), $request->string('alasan')->toString(), $kepalaSekolah);

        return ApiResponse::success(new GuruResource($guru), "Pendaftaran {$guru->user->name} ditolak.");
    }

    /**
     * Mengaktifkan atau menonaktifkan akun guru. Menonaktifkan mencabut semua sesi login guru.
     */
    public function ubahStatus(UbahStatusAkunRequest $request, int $id, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $guru = $this->guruService->ubahStatus($this->cariGuru($id), $request->status(), $kepalaSekolah);

        return ApiResponse::success(new GuruResource($guru), "Akun {$guru->user->name} sekarang berstatus {$guru->user->status->label()}.");
    }

    private function cariGuru(int $id): Guru
    {
        return Guru::query()->with('user')->findOrFail($id);
    }
}
