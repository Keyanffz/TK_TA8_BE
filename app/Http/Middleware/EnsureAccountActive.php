<?php

namespace App\Http\Middleware;

use App\Enums\StatusAkun;
use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->status !== StatusAkun::Aktif) {
            return ApiResponse::error($user->status->pesanAksesDitolak(), $user->status->kodeErrorAkses());
        }

        return $next($request);
    }
}
