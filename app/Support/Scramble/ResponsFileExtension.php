<?php

namespace App\Support\Scramble;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Untuk endpoint yang membalas file (kwitansi, rapor PDF, bukti transfer, ekspor Excel, media), Scramble
 * tetap menambahkan `application/json` berisi objek kosong di samping tipe file dari atribut `#[Response]`.
 * Entri itu dibuang supaya tipe yang digenerate FE hanya berisi tipe file yang benar.
 */
class ResponsFileExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        foreach ($operation->responses ?? [] as $respons) {
            if (! $respons instanceof Response || (int) $respons->code >= 400 || count($respons->content) < 2) {
                continue;
            }

            $json = $respons->content['application/json'] ?? null;

            if ($json instanceof Schema && $json->toArray() === ['type' => 'object']) {
                unset($respons->content['application/json']);
            }
        }
    }
}
