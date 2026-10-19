<?php

namespace App\Models;

use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu izin modul untuk satu guru (A2.13). Tidak ada baris berarti guru tidak punya izin modul itu.
 */
#[Fillable(['guru_id', 'modul', 'level'])]
class IzinGuru extends Model
{
    protected $table = 'izin_guru';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modul' => ModulIzin::class,
            'level' => LevelIzin::class,
        ];
    }

    /**
     * @return BelongsTo<Guru, $this>
     */
    public function guru(): BelongsTo
    {
        return $this->belongsTo(Guru::class);
    }
}
