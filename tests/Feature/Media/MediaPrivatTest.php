<?php

use App\Services\MediaService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('murid/kirana.jpg', 'isi-foto');
});

it('menyajikan file private lewat signed URL tanpa header Authorization', function () {
    $url = app(MediaService::class)->urlPrivat('murid/kirana.jpg');

    expect($url)->toStartWith(config('app.url').'/api/v1/media/')
        ->and($url)->not->toContain('murid/kirana.jpg');

    $response = $this->get((string) $url)->assertOk();

    expect($response->streamedContent())->toBe('isi-foto')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=1800');
});

it('menolak signed URL yang sudah lewat 30 menit', function () {
    $url = app(MediaService::class)->urlPrivat('murid/kirana.jpg');
    $this->travel(31)->minutes();

    $this->getJson((string) $url)
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN')
        ->assertJsonPath('message', 'Tautan file sudah kedaluwarsa atau tidak valid. Muat ulang halaman untuk mendapatkan tautan baru.');
});

it('menolak signed URL yang diubah', function () {
    $url = app(MediaService::class)->urlPrivat('murid/kirana.jpg');

    $this->getJson(str_replace('signature=', 'signature=0', (string) $url))->assertForbidden();
});

it('membalas 404 untuk token yang tidak bisa dibuka atau file yang sudah dihapus', function () {
    $url = app(MediaService::class)->urlPrivat('murid/kirana.jpg');
    Storage::disk('local')->delete('murid/kirana.jpg');

    $this->getJson((string) $url)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
});
