<?php

namespace App\Http\Middleware;

use App\Enums\KodeError;
use App\Enums\Role;
use App\Exceptions\ApiExceptionRenderer;
use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemakaian di route: `->middleware('role:super_admin,guru')`.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $diizinkan = array_map(fn (string $role): Role => Role::from($role), $roles);
        $user = $request->user();

        if (! $user instanceof User || ! in_array($user->role, $diizinkan, true)) {
            return ApiResponse::error(ApiExceptionRenderer::PESAN_TIDAK_BERHAK, KodeError::Forbidden);
        }

        return $next($request);
    }
}
