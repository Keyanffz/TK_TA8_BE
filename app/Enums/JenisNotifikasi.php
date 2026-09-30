<?php

namespace App\Enums;

/**
 * Nilai `jenis` pada notifikasi (A7 "Bentuk notifikasi"). FE memakainya untuk ikon dan pengelompokan.
 */
enum JenisNotifikasi: string
{
    case TagihanBaru = 'tagihan_baru';
    case TagihanTertunda = 'tagihan_tertunda';
    case PengingatTagihan = 'pengingat_tagihan';
    case TagihanTerlambat = 'tagihan_terlambat';
    case PembayaranMasuk = 'pembayaran_masuk';
    case PembayaranDiterima = 'pembayaran_diterima';
    case PembayaranDitolak = 'pembayaran_ditolak';
    case RaporDiajukan = 'rapor_diajukan';
    case RaporRevisi = 'rapor_revisi';
    case RaporTerbit = 'rapor_terbit';
    case PengumumanBaru = 'pengumuman_baru';
    case PendaftaranBaru = 'pendaftaran_baru';
    case PendaftaranDiproses = 'pendaftaran_diproses';
    case AnakTertaut = 'anak_tertaut';

    public function label(): string
    {
        return match ($this) {
            self::TagihanBaru => 'Tagihan baru',
            self::TagihanTertunda => 'Tagihan belum dibuat',
            self::PengingatTagihan => 'Pengingat tagihan',
            self::TagihanTerlambat => 'Tagihan terlambat',
            self::PembayaranMasuk => 'Pembayaran masuk',
            self::PembayaranDiterima => 'Pembayaran diterima',
            self::PembayaranDitolak => 'Pembayaran ditolak',
            self::RaporDiajukan => 'Rapor diajukan',
            self::RaporRevisi => 'Rapor perlu revisi',
            self::RaporTerbit => 'Rapor terbit',
            self::PengumumanBaru => 'Pengumuman baru',
            self::PendaftaranBaru => 'Pendaftaran PPDB baru',
            self::PendaftaranDiproses => 'Pendaftaran PPDB diproses',
            self::AnakTertaut => 'Wali murid tertaut',
        };
    }
}
