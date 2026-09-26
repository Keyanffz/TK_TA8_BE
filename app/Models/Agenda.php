<?php

namespace App\Models;

use App\Enums\JenisAgenda;
use Database\Factories\AgendaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['judul', 'deskripsi', 'tanggal_mulai', 'tanggal_selesai', 'jenis', 'is_publik', 'dibuat_oleh'])]
class Agenda extends Model
{
    /** @use HasFactory<AgendaFactory> */
    use HasFactory;

    protected $table = 'agenda';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'jenis' => JenisAgenda::class,
            'is_publik' => 'boolean',
        ];
    }

    /**
     * Agenda yang bersinggungan dengan bulan itu, termasuk yang mulai sebelum atau berakhir sesudahnya.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBerlangsungDi(Builder $query, Carbon $bulan): void
    {
        $query->whereDate('tanggal_mulai', '<=', $bulan->copy()->endOfMonth())
            ->whereDate('tanggal_selesai', '>=', $bulan->copy()->startOfMonth());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }
}
