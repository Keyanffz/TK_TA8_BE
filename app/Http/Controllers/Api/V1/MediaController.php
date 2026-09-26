<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MediaService;
use Dedoc\Scramble\Attributes\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    /**
     * Menyajikan file private dari signed URL (`*_url`). Tidak memakai token Bearer; hak akses
     * sudah dicek saat URL dibuat, dan URL berlaku 30 menit. Isinya gambar JPEG atau dokumen PDF (PPDB).
     */
    #[Response(200, 'Gambar JPEG atau dokumen PDF', mediaType: 'application/octet-stream', type: 'string', format: 'binary')]
    public function __invoke(string $token, MediaService $media): StreamedResponse
    {
        return $media->respons($token) ?? abort(404);
    }
}
