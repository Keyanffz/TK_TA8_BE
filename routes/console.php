<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tagihan:generate')->monthlyOn(1, '00:10');
Schedule::command('tagihan:tandai-terlambat')->dailyAt('00:30');
Schedule::command('tagihan:pengingat')->dailyAt('07:00');
