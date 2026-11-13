<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rapor {{ $kelas->nama }} Semester {{ $semester }}</title>
    @include('pdf.bagian.gaya-rapor')
    <style>
        .rapor-berikutnya { page-break-before: always; }
    </style>
</head>
<body>
    @foreach ($semuaRapor as $isi)
        <div @class(['rapor-berikutnya' => ! $loop->first])>
            @include('pdf.bagian.isi-rapor', $isi)
        </div>
    @endforeach
</body>
</html>
