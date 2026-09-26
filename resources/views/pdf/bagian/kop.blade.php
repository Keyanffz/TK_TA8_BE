{{-- Kop sekolah bersama untuk kwitansi dan rapor. Butuh $sekolah dari PengaturanService::kopSekolah(). --}}
<table class="kop">
    <tr>
        @if ($sekolah['logo'])
            <td style="width: 60px;"><img src="{{ $sekolah['logo'] }}" alt="Logo"></td>
        @endif
        <td>
            <div class="nama-sekolah">{{ $sekolah['nama'] }}</div>
            @if ($sekolah['alamat'] !== '')
                <div>{{ $sekolah['alamat'] }}</div>
            @endif
            @if ($sekolah['telepon'] !== '' || $sekolah['email'] !== '')
                <div class="kecil">{{ collect([$sekolah['telepon'], $sekolah['email']])->filter()->join(' · ') }}</div>
            @endif
        </td>
    </tr>
</table>
