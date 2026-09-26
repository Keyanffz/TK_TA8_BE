<?php

namespace App\Models;

use Database\Factories\RaporDetailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['rapor_id', 'elemen_penilaian_id', 'deskripsi', 'foto_path'])]
class RaporDetail extends Model
{
    /** @use HasFactory<RaporDetailFactory> */
    use HasFactory;

    protected $table = 'rapor_detail';

    /**
     * @return BelongsTo<Rapor, $this>
     */
    public function rapor(): BelongsTo
    {
        return $this->belongsTo(Rapor::class);
    }

    /**
     * @return BelongsTo<ElemenPenilaian, $this>
     */
    public function elemenPenilaian(): BelongsTo
    {
        return $this->belongsTo(ElemenPenilaian::class);
    }
}
