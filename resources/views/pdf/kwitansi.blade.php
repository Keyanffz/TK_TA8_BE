<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kwitansi {{ $pembayaran->kode }}</title>
    <style>
        @page { margin: 28px 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; margin: 0; }
        .kop { border-bottom: 2px solid #15803d; padding-bottom: 6px; margin-bottom: 10px; }
        .kop td { vertical-align: middle; }
        .kop img { height: 52px; }
        .nama-sekolah { font-size: 16px; font-weight: bold; color: #15803d; }
        h1 { font-size: 13px; text-align: center; margin: 0 0 8px; letter-spacing: 2px; }
        table { width: 100%; border-collapse: collapse; }
        .rincian td { padding: 2px 6px; }
        .rincian td.label { width: 32%; color: #4b5563; }
        .jumlah { margin-top: 8px; border: 1px solid #15803d; }
        .jumlah td { padding: 4px 6px; }
        .jumlah .total { font-size: 14px; font-weight: bold; color: #15803d; text-align: right; }
        .tanda-tangan { margin-top: 10px; }
        .tanda-tangan td { text-align: center; width: 50%; }
        .kecil { font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
    @include('pdf.bagian.kop')

    <h1>KWITANSI PEMBAYARAN</h1>

    <table class="rincian">
        <tr><td class="label">Nomor kwitansi</td><td>{{ $pembayaran->kode }}</td></tr>
        <tr><td class="label">Nomor tagihan</td><td>{{ $tagihan->kode }}</td></tr>
        <tr><td class="label">Nama murid</td><td>{{ $murid->nama_lengkap }} (NIS {{ $murid->nis }})</td></tr>
        @if ($kelas)
            <tr><td class="label">Kelas</td><td>{{ $kelas->nama }}</td></tr>
        @endif
        <tr><td class="label">Pembayaran untuk</td><td>{{ $tagihan->label() }}</td></tr>
        <tr><td class="label">Tanggal bayar</td><td>{{ $pembayaran->tanggal_bayar->translatedFormat('j F Y') }}</td></tr>
        <tr><td class="label">Metode</td><td>{{ $pembayaran->metode->label() }}@if ($pembayaran->bank_pengirim) ({{ $pembayaran->bank_pengirim }} a.n. {{ $pembayaran->nama_pengirim }})@endif</td></tr>
    </table>

    <table class="jumlah">
        <tr><td>Nominal</td><td style="text-align: right;">{{ \App\Support\Rupiah::format($tagihan->nominal) }}</td></tr>
        @if ($tagihan->potongan > 0)
            <tr><td>Potongan</td><td style="text-align: right;">- {{ \App\Support\Rupiah::format($tagihan->potongan) }}</td></tr>
        @endif
        <tr><td><strong>Jumlah dibayar</strong></td><td class="total">{{ \App\Support\Rupiah::format($pembayaran->jumlah) }}</td></tr>
    </table>

    <table class="tanda-tangan">
        <tr>
            <td></td>
            <td>
                Diterima {{ $pembayaran->diverifikasi_at?->translatedFormat('j F Y') }}<br>
                Petugas keuangan
                <br><br>
                <strong>{{ $pembayaran->verifikator?->name }}</strong>
            </td>
        </tr>
    </table>

    <p class="kecil">Dicetak {{ now()->translatedFormat('j F Y H:i') }} WIB.</p>
</body>
</html>
