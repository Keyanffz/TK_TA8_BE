<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rekap Absensi {{ $rentang }}</title>
    <style>
        @page { margin: 28px 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; margin: 0; }
        .kop { width: 100%; border-collapse: collapse; border-bottom: 2px solid #15803d; padding-bottom: 6px; margin-bottom: 10px; }
        .kop td { vertical-align: middle; }
        .kop img { height: 52px; }
        .nama-sekolah { font-size: 16px; font-weight: bold; color: #15803d; }
        h1 { font-size: 13px; text-align: center; margin: 0; letter-spacing: 1px; }
        .rentang { text-align: center; margin: 2px 0 10px; }
        .rekap { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .rekap th, .rekap td { border: 1px solid #9ca3af; padding: 4px 5px; vertical-align: top; }
        .rekap th { background: #dcfce7; font-weight: bold; }
        .rekap td.teks { word-wrap: break-word; }
        .rekap .angka { text-align: center; }
        .kecil { font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
    @include('pdf.bagian.kop')

    <h1>REKAP ABSENSI GURU DAN KEPALA SEKOLAH</h1>
    <p class="rentang">{{ $rentang }}</p>

    <table class="rekap">
        <thead>
            <tr>
                <th style="width: 24px;">No</th>
                <th style="width: 190px;">Nama</th>
                <th style="width: 120px;">Jabatan</th>
                <th class="angka">Hadir</th>
                <th class="angka">Terlambat</th>
                <th class="angka">Izin</th>
                <th class="angka">Sakit</th>
                <th class="angka">Tidak Hadir</th>
                <th class="angka">Tidak Absen Pulang</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rekap as $nomor => $baris)
                <tr>
                    <td class="angka">{{ $nomor + 1 }}</td>
                    <td class="teks">{{ $baris['user']['nama'] }}</td>
                    <td class="teks">{{ $baris['user']['jabatan'] }}</td>
                    <td class="angka">{{ $baris['hadir'] }}</td>
                    <td class="angka">{{ $baris['terlambat'] }}</td>
                    <td class="angka">{{ $baris['izin'] }}</td>
                    <td class="angka">{{ $baris['sakit'] }}</td>
                    <td class="angka">{{ $baris['tidak_hadir'] }}</td>
                    <td class="angka">{{ $baris['tidak_absen_pulang'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="angka">Belum ada peserta absensi.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="kecil">Dicetak {{ now()->translatedFormat('j F Y H:i') }} WIB.</p>
</body>
</html>
