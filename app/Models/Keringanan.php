<?php

namespace App\Models;

use App\Enums\TipeKeringanan;
use Database\Factories\KeringananFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['murid_id', 'jenis_tagihan_id', 'tipe', 'nilai', 'alasan', 'berlaku_mulai', 'berlaku_sampai', 'dibuat_oleh'])]
class Keringanan extends Model
{
    /** @use HasFactory<KeringananFactory> */
    use HasFactory;

    protected $table = 'keringanan';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipe' => TipeKeringanan::class,
            'nilai' => 'integer',
            'berlaku_mulai' => 'date',
            'berlaku_sampai' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Murid, $this>
     */
    public function murid(): BelongsTo
    {
        return $this->belongsTo(Murid::class);
    }

    /**
     * @return BelongsTo<JenisTagihan, $this>
     */
    public function jenisTagihan(): BelongsTo
    {
        return $this->belongsTo(JenisTagihan::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }
}
