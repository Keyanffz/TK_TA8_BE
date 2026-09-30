<?php

namespace App\Http\Controllers\Api\V1\Guru;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guru\DaftarGuruRequest;
use App\Http\Requests\Guru\SimpanGuruRequest;
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
     * Filter `filter[status]` = `aktif` | `nonaktif`. Urutan `sort` = `nama` | `created_at`
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
     * `email` adalah alamat akun Google guru (disimpan huruf kecil, unik tanpa membedakan huruf besar). Guru tidak
     * punya password; guru masuk lewat `POST /auth/staff/google` dengan akun Google beremail itu.
     */
    public function store(SimpanGuruRequest $request): JsonResponse
    {
        $guru = $this->guruService->buat($request->validated(), $request->foto());

        return ApiResponse::success(
            new GuruResource($guru),
            "Akun guru {$guru->user->name} dibuat. Guru bisa masuk dengan akun Google {$guru->user->email}.",
            status: 201,
        );
    }

    /**
     * Mengubah data guru, termasuk izin kelola keuangan dan tampil di landing page.
     *
     * Mengganti `email` melepas akun Google yang sebelumnya terikat, jadi guru masuk dengan akun Google email baru.
     */
    public function update(SimpanGuruRequest $request, int $id, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $guru = $this->guruService->perbarui($this->cariGuru($id), $request->validated(), $request->foto(), $kepalaSekolah);

        return ApiResponse::success(new GuruResource($guru), 'Data guru tersimpan.');
    }

    /**
     * Mengaktifkan atau menonaktifkan akun guru. Menonaktifkan mencabut semua sesi login guru dan menolak login
     * berikutnya (403 `ACCOUNT_INACTIVE`). Akun guru tidak pernah dihapus, supaya riwayat kelas, kegiatan, dan rapor
     * tetap utuh.
     */
    public function ubahStatus(UbahStatusAkunRequest $request, int $id, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $guru = $this->guruService->ubahStatus($this->cariGuru($id), $request->status(), $kepalaSekolah);

        return ApiResponse::success(new GuruResource($guru), "Akun {$guru->user->name} sekarang berstatus {$guru->user->status->label()}.");
    }

    /**
     * Melepas akun Google yang terikat ke guru, supaya guru bisa masuk dengan akun Google baru beremail sama.
     *
     * Semua sesi login guru itu dicabut, jadi guru harus masuk lagi dengan Google. Ditolak 422 `BUSINESS_RULE` kalau
     * guru belum pernah masuk dengan Google (`terhubung_google` false). Dicatat di log aktivitas `akun` (event `google_direset`).
     */
    public function resetGoogle(int $id, #[CurrentUser] User $kepalaSekolah): JsonResponse
    {
        $guru = $this->guruService->resetGoogle($this->cariGuru($id), $kepalaSekolah);

        return ApiResponse::success(
            new GuruResource($guru),
            "Tautan Google {$guru->user->name} direset dan semua sesinya dikeluarkan. Login Google berikutnya dengan {$guru->user->email} memakai akun Google yang dipilih saat itu.",
        );
    }

    private function cariGuru(int $id): Guru
    {
        return Guru::query()->with('user')->findOrFail($id);
    }
}
