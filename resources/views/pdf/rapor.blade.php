<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rapor {{ $rapor->murid->nama_lengkap }}</title>
    @include('pdf.bagian.gaya-rapor')
</head>
<body>
    @include('pdf.bagian.isi-rapor')
</body>
</html>
