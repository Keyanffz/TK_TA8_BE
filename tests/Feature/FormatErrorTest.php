<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/_uji')->group(function () {
        Route::post('/validasi', fn (Request $request) => $request->validate(['email' => ['required', 'email']]));
        Route::get('/aturan-bisnis', fn () => throw new BusinessRuleException('Tagihan yang sudah lunas tidak bisa dibatalkan.'));
        Route::get('/user/{id}', fn (int $id) => User::query()->findOrFail($id));
        Route::get('/terproteksi', fn () => 'rahasia')->middleware('auth:sanctum');
        Route::get('/terlarang', fn () => abort(403));
        Route::get('/dibatasi', fn () => 'ok')->middleware('throttle:1,1');
        Route::get('/rusak', fn () => throw new RuntimeException('detail internal SQLSTATE'));
    });
});

it('membalas validasi gagal dengan 422 VALIDATION_ERROR dan pesan bahasa Indonesia', function () {
    $this->postJson('/api/v1/_uji/validasi', [])
        ->assertStatus(422)
        ->assertExactJson([
            'success' => false,
            'message' => 'Data tidak valid',
            'code' => 'VALIDATION_ERROR',
            'errors' => ['email' => ['Email wajib diisi.']],
        ]);
});

it('membalas pelanggaran aturan bisnis dengan 422 BUSINESS_RULE dan pesan aslinya', function () {
    $this->getJson('/api/v1/_uji/aturan-bisnis')
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Tagihan yang sudah lunas tidak bisa dibatalkan.',
            'code' => 'BUSINESS_RULE',
            'errors' => null,
        ]);
});

it('membalas data yang tidak ada dengan 404 NOT_FOUND', function () {
    $this->getJson('/api/v1/_uji/user/999')
        ->assertNotFound()
        ->assertJson(['code' => 'NOT_FOUND', 'message' => ApiExceptionRenderer::PESAN_DATA_TIDAK_ADA]);
});

it('membalas endpoint yang tidak ada atau metode yang salah dengan 404 NOT_FOUND', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertNotFound()
        ->assertJson(['code' => 'NOT_FOUND', 'message' => ApiExceptionRenderer::PESAN_ENDPOINT_TIDAK_ADA]);
})->with([
    'endpoint tidak ada' => ['GET', '/api/v1/tidak-ada'],
    'metode salah' => ['POST', '/api/v1/health'],
]);

it('membalas request tanpa token ke route terproteksi dengan 401 UNAUTHENTICATED', function () {
    $this->getJson('/api/v1/_uji/terproteksi')
        ->assertUnauthorized()
        ->assertJson(['success' => false, 'code' => 'UNAUTHENTICATED']);
});

it('membalas JSON walaupun klien meminta HTML', function () {
    $this->get('/api/v1/_uji/terproteksi', ['Accept' => 'text/html'])
        ->assertUnauthorized()
        ->assertJson(['code' => 'UNAUTHENTICATED']);
});

it('membalas akses terlarang dengan 403 FORBIDDEN', function () {
    $this->getJson('/api/v1/_uji/terlarang')
        ->assertForbidden()
        ->assertJson(['code' => 'FORBIDDEN', 'message' => ApiExceptionRenderer::PESAN_TIDAK_BERHAK]);
});

it('membalas request berlebihan dengan 429 TOO_MANY_REQUESTS beserta header Retry-After', function () {
    $this->getJson('/api/v1/_uji/dibatasi')->assertOk();

    $this->getJson('/api/v1/_uji/dibatasi')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJson(['code' => 'TOO_MANY_REQUESTS', 'message' => 'Terlalu banyak percobaan. Coba lagi dalam 60 detik.']);
});

it('membalas error tak terduga dengan 500 SERVER_ERROR tanpa membocorkan detail internal', function () {
    $response = $this->getJson('/api/v1/_uji/rusak');

    $response->assertStatus(500)
        ->assertJson(['code' => 'SERVER_ERROR', 'message' => ApiExceptionRenderer::PESAN_SERVER]);
    expect($response->getContent())->not->toContain('SQLSTATE');
});
