<?php

namespace App\Support\Scramble;

use App\Enums\KodeError;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

/**
 * Melengkapi respons error per operasi:
 * - kode dari konfigurasi route yang tidak terdeteksi Scramble dari isi controller: middleware
 *   `akun.aktif` (ACCOUNT_*), `password.diganti` (PASSWORD_WAJIB_DIGANTI), `role:`, `can:`, dan `signed`
 *   (FORBIDDEN), `throttle:` (TOO_MANY_REQUESTS),
 *   parameter path yang datanya bisa tidak ada (NOT_FOUND);
 * - beberapa exception dengan status sama (misal 422 VALIDATION_ERROR dan BUSINESS_RULE) digabung
 *   ke satu respons, karena OpenAPI hanya menyimpan satu respons per status.
 */
class ResponsErrorRouteExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $middleware = array_values(array_filter($routeInfo->route->gatherMiddleware(), 'is_string'));
        $diawali = fn (string $awalan): bool => array_filter($middleware, fn (string $m) => Str::startsWith($m, $awalan)) !== [];

        $kodePerStatus = [];
        $responsSukses = [];

        foreach ($operation->responses ?? [] as $respons) {
            $resolved = $respons instanceof Reference ? $respons->resolve() : $respons;
            $status = $resolved instanceof Response ? (int) $resolved->code : 0;

            if ($status >= 400) {
                $kodePerStatus[$status] = [...$kodePerStatus[$status] ?? [], ...$this->kodeDari($resolved)];
            } else {
                $responsSukses[] = $respons;
            }
        }

        if ($diawali('role:') || $diawali('can:') || $diawali('signed')) {
            $kodePerStatus[403] = [KodeError::Forbidden, ...$kodePerStatus[403] ?? []];
        }
        if (in_array('akun.aktif', $middleware, true)) {
            $kodePerStatus[403] = [...$kodePerStatus[403] ?? [], KodeError::AccountPending, KodeError::AccountRejected, KodeError::AccountInactive];
        }
        if (in_array('password.diganti', $middleware, true)) {
            $kodePerStatus[403] = [...$kodePerStatus[403] ?? [], KodeError::PasswordWajibDiganti];
        }
        if ($routeInfo->route->parameterNames() !== []) {
            $kodePerStatus[404] = [KodeError::NotFound];
        }
        if ($diawali('throttle:')) {
            $kodePerStatus[429] = [KodeError::TooManyRequests];
        }

        ksort($kodePerStatus);
        $operation->responses = $responsSukses;

        foreach ($kodePerStatus as $status => $kode) {
            $kode = array_values(array_unique($kode, SORT_REGULAR));

            if ($kode !== []) {
                $operation->addResponse(SkemaErrorA7::respons($kode, implode(' / ', array_map(fn (KodeError $k) => $k->value, $kode)), $status));
            }
        }
    }

    /**
     * @return list<KodeError>
     */
    private function kodeDari(Response $respons): array
    {
        $skemaKode = $respons->toArray()['content']['application/json']['schema']['properties']['code'] ?? [];
        $nilai = $skemaKode['enum'] ?? (isset($skemaKode['const']) ? [$skemaKode['const']] : []);

        return array_values(array_filter(array_map(fn (mixed $v) => is_string($v) ? KodeError::tryFrom($v) : null, $nilai)));
    }
}
