<?php

namespace App\Support;

use App\Enums\KodeError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class ApiResponse
{
    public const PESAN_SUKSES = 'Berhasil';

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function success(mixed $data = null, string $message = self::PESAN_SUKSES, ?array $meta = null, int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
        ], $status);
    }

    /**
     * Koleksi diterima (bukan paginator + nama class Resource) supaya Scramble
     * bisa membaca tipe item di `data`.
     *
     * @param  AnonymousResourceCollection  $collection  hasil `XResource::collection($paginator)`
     */
    public static function paginated(AnonymousResourceCollection $collection, string $message = self::PESAN_SUKSES): JsonResponse
    {
        /** @var LengthAwarePaginator<int, mixed> $paginator */
        $paginator = $collection->resource;

        return self::success($collection, $message, [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    /**
     * @param  int|null  $status  null = status bawaan kode error
     * @param  array<string, array<int, string>>|null  $errors
     * @param  array<string, string>  $headers
     */
    public static function error(string $message, KodeError $code, ?int $status = null, ?array $errors = null, array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $message,
            'code' => $code->value,
            'errors' => $errors,
        ], $status ?? $code->status(), $headers);
    }
}
