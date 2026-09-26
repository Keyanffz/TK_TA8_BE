<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pemeriksaan "boleh melihat data ini" (B4) untuk Policy `view` yang hanya pernah menolak dengan 404
 * (`Response::denyAsNotFound()`). Dipakai sebagai ganti `Gate::authorize('view', …)` supaya dokumentasi
 * OpenAPI tidak mencantumkan 403 yang tidak mungkin terjadi. Policy yang bisa membalas 403 (misalnya
 * pembayaran untuk guru tanpa izin keuangan) tetap memakai `Gate::authorize`.
 */
final class Jangkauan
{
    /**
     * @throws NotFoundHttpException
     */
    public static function pastikanTerlihat(Model $model): void
    {
        if (Gate::denies('view', $model)) {
            throw new NotFoundHttpException;
        }
    }
}
