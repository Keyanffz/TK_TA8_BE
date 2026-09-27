<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu Akun {{ $murid->nis }}</title>
    <style>
        @page { margin: 22px 24px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; margin: 0; }
        .kop { border-bottom: 2px solid #15803d; padding-bottom: 5px; margin-bottom: 10px; }
        .kop td { vertical-align: middle; }
        .kop img { height: 40px; }
        .nama-sekolah { font-size: 13px; font-weight: bold; color: #15803d; }
        h1 { font-size: 11px; text-align: center; margin: 0 0 10px; letter-spacing: 1px; }
        table { width: 100%; border-collapse: collapse; }
        .rincian td { padding: 3px 4px; vertical-align: top; }
        .rincian td.label { width: 34%; color: #4b5563; }
        .username { margin: 10px 0; border: 1px solid #15803d; padding: 6px; text-align: center; }
        .username .nilai { font-size: 18px; font-weight: bold; letter-spacing: 2px; color: #15803d; }
        .keterangan { margin-top: 8px; }
        .kecil { font-size: 8px; color: #6b7280; }
    </style>
</head>
<body>
    @include('pdf.bagian.kop')

    <h1>KARTU AKUN WALI MURID</h1>

    <table class="rincian">
        <tr><td class="label">Nama anak</td><td>{{ $murid->nama_lengkap }}</td></tr>
        <tr><td class="label">Kelas</td><td>{{ $kelas?->nama ?? 'Belum ada kelas' }}</td></tr>
    </table>

    <div class="username">
        <div class="kecil">Username (NIS anak)</div>
        <div class="nilai">{{ $murid->nis }}</div>
    </div>

    <p class="keterangan">Password awal: tanggal lahir anak (DDMMYYYY), wajib diganti saat login pertama.</p>
    <p>Masuk di: {{ $alamat_website }}</p>

    <p class="kecil">Simpan kartu ini. Kalau lupa password, hubungi pihak sekolah untuk mengembalikannya ke password awal.</p>
</body>
</html>
