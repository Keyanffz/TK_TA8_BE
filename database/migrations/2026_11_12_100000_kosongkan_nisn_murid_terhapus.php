<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Murid yang dihapus sebelum perbaikan ini masih memegang NISN-nya, sehingga NISN itu tidak bisa dipakai lagi.
     */
    public function up(): void
    {
        DB::table('murid')->whereNotNull('deleted_at')->whereNotNull('nisn')->update(['nisn' => null]);
    }
};
