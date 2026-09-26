<?php

use Illuminate\Support\Facades\Route;

it('membuka /docs/api di environment selain production', function () {
    $this->get('/docs/api')->assertOk();
});

it('menutup /docs/api di production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/docs/api')->assertForbidden();
});

it('mendokumentasikan auth Bearer dan format error A7', function () {
    Route::middleware(['api', 'auth:sanctum'])->get('/api/v1/_uji/terproteksi', fn () => 'ok');

    $dokumen = $this->getJson('/docs/api.json')->assertOk()->json();

    expect($dokumen['servers'][0]['url'])->toEndWith('/api/v1')
        ->and($dokumen['components']['securitySchemes'])->toContain(['type' => 'http', 'scheme' => 'bearer'])
        ->and($dokumen['paths']['/health']['get']['security'])->toBe([])
        ->and($dokumen['components']['responses']['AuthenticationException']['content']['application/json']['schema']['properties'])
        ->toHaveKeys(['success', 'message', 'code', 'errors']);
});
