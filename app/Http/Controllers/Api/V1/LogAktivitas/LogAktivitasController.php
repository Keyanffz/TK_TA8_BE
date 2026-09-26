<?php

namespace App\Http\Controllers\Api\V1\LogAktivitas;

use App\Http\Controllers\Controller;
use App\Http\Requests\LogAktivitas\DaftarLogAktivitasRequest;
use App\Http\Resources\LogAktivitasResource;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Models\Activity;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class LogAktivitasController extends Controller
{
    /**
     * Log aktivitas (B7), terbaru lebih dulu. Filter `filter[user_id]` (pelaku), `filter[jenis]`, dan
     * `filter[tanggal]` (YYYY-MM-DD).
     */
    public function __invoke(DaftarLogAktivitasRequest $request): JsonResponse
    {
        $log = QueryBuilder::for(Activity::query()->with('causer')->latest()->latest('id'), $request)
            ->allowedFilters(
                AllowedFilter::exact('user_id', 'causer_id'),
                AllowedFilter::exact('jenis', 'log_name'),
                AllowedFilter::callback('tanggal', fn (Builder $query, mixed $tanggal) => $query->whereDate('created_at', (string) $tanggal)),
            )
            ->paginate($request->perHalaman())
            ->withQueryString();

        return ApiResponse::paginated(LogAktivitasResource::collection($log));
    }
}
