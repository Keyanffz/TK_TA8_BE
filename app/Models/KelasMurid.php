<?php

namespace App\Models;

use App\Enums\StatusKelasMurid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Penempatan murid di kelas. Pivot dengan id sendiri karena statusnya
 * diperbarui saat kenaikan kelas.
 */
class KelasMurid extends Pivot
{
    public $incrementing = true;

    protected $table = 'kelas_murid';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StatusKelasMurid::class,
        ];
    }

    /**
     * @return BelongsTo<Kelas, $this>
     */
    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }

    /**
     * @return BelongsTo<Murid, $this>
     */
    public function murid(): BelongsTo
    {
        return $this->belongsTo(Murid::class);
    }
}
