{{-- Isi satu rapor. Butuh $sekolah, $rapor, $detail, dan $kepalaSekolah dari RaporPdfService::isi(). --}}
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
