<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rapor {{ $rapor->murid->nama_lengkap }}</title>
    <style>
        @page { margin: 32px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; margin: 0; line-height: 1.45; }
        .kop { border-bottom: 2px solid #15803d; padding-bottom: 6px; margin-bottom: 12px; }
        .kop td { vertical-align: middle; }
        .kop img { height: 52px; }
        .nama-sekolah { font-size: 16px; font-weight: bold; color: #15803d; }
        h1 { font-size: 14px; text-align: center; margin: 0 0 2px; letter-spacing: 1px; }
        .subjudul { text-align: center; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        .identitas td { padding: 2px 4px; }
        .identitas td.label { width: 24%; color: #4b5563; }
        .elemen { margin-top: 12px; border: 1px solid #d1d5db; page-break-inside: avoid; }
        .elemen .nama { background: #f0fdf4; color: #166534; font-weight: bold; padding: 5px 8px; border-bottom: 1px solid #d1d5db; }
        .elemen .isi { padding: 6px 8px; }
        .elemen img { max-width: 220px; max-height: 160px; margin-top: 6px; }
        .catatan { margin-top: 12px; border: 1px solid #d1d5db; padding: 6px 8px; }
        .tanda-tangan { margin-top: 24px; page-break-inside: avoid; }
        .tanda-tangan td { text-align: center; width: 50%; vertical-align: top; }
        .kecil { font-size: 9px; color: #6b7280; }
        .pratinjau { border: 1px solid #b45309; color: #b45309; text-align: center; padding: 3px; margin-bottom: 10px; }
    </style>
</head>
<body>
    @include('pdf.bagian.kop')

    @if ($rapor->status !== \App\Enums\StatusRapor::Terbit)
        <div class="pratinjau">Pratinjau: rapor berstatus {{ $rapor->status->label() }} dan belum terbit.</div>
    @endif

    <h1>RAPOR PERKEMBANGAN ANAK</h1>
    <p class="subjudul">Semester {{ $rapor->semester }} Tahun Ajaran {{ $rapor->tahunAjaran->nama }}</p>

    <table class="identitas">
        <tr><td class="label">Nama</td><td>{{ $rapor->murid->nama_lengkap }}</td><td class="label">Kelas</td><td>{{ $rapor->kelas->nama }}</td></tr>
        <tr><td class="label">NIS</td><td>{{ $rapor->murid->nis }}</td><td class="label">Tinggi badan</td><td>{{ $rapor->tinggi_badan !== null ? $rapor->tinggi_badan.' cm' : '-' }}</td></tr>
        <tr><td class="label">Nama panggilan</td><td>{{ $rapor->murid->nama_panggilan }}</td><td class="label">Berat badan</td><td>{{ $rapor->berat_badan !== null ? $rapor->berat_badan.' kg' : '-' }}</td></tr>
    </table>

    @foreach ($detail as $satu)
        <div class="elemen">
            <div class="nama">{{ $satu['elemen'] }}</div>
            <div class="isi">
                {!! nl2br(e($satu['deskripsi'] ?? '-')) !!}
                @if ($satu['foto'])
                    <br><img src="{{ $satu['foto'] }}" alt="Foto {{ $satu['elemen'] }}">
                @endif
            </div>
        </div>
    @endforeach

    <div class="catatan">
        <strong>Catatan guru</strong><br>
        {!! nl2br(e($rapor->catatan_guru ?? '-')) !!}
    </div>

    <table class="tanda-tangan">
        <tr>
            <td>
                Guru Kelas
                <br><br><br><br>
                <strong>{{ $rapor->pembuat->user->name }}</strong>
            </td>
            <td>
                {{ $rapor->terbit_at?->translatedFormat('j F Y') }}<br>
                Kepala Sekolah
                <br><br><br>
                <strong>{{ $kepalaSekolah }}</strong>
            </td>
        </tr>
    </table>

    <p class="kecil">Dicetak {{ now()->translatedFormat('j F Y H:i') }} WIB.</p>
</body>
</html>
