<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('kode-tautan:bersihkan')->dailyAt('01:00');
