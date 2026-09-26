<?php

return [
    /*
     * Proxy yang dipercaya untuk header X-Forwarded-* (dibaca middleware TrustProxies bawaan Laravel).
     * Isi dengan IP/CIDR dipisah koma, atau `*` kalau aplikasi hanya bisa diakses lewat proxy. Kosong = tidak
     * ada proxy yang dipercaya. Di server wajib berisi IP server FE: FE memakai pola BFF, jadi tanpa itu semua
     * pengunjung tercatat dengan IP server FE dan berbagi satu kuota rate limiter. Signed URL file private juga
     * dibentuk dari host dan skema request, jadi reverse proxy HTTPS di depan BE ikut didaftarkan.
     */
    'proxies' => env('TRUSTED_PROXIES'),
];
