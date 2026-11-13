{{-- Gaya rapor bersama untuk PDF satu rapor dan satu kelas. --}}
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
