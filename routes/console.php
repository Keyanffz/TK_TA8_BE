<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tagihan:generate')->monthlyOn(1, '00:10');
Schedule::command('tagihan:tandai-terlambat')->dailyAt('00:30');
Schedule::command('tagihan:pengingat')->dailyAt('07:00');

// Jam masuk tutup diatur Kepala Sekolah, jadi command dijalankan berkala dan sendiri memeriksa apakah jam itu
// sudah lewat di hari kerja; menjalankannya berulang tidak membuat baris ganda.
Schedule::command('absensi:tandai-tidak-hadir')->everyTenMinutes();
Schedule::command('absensi:hapus-foto-lama')->dailyAt('01:00');
