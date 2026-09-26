<?php

namespace App\Models;

use Database\Factories\WaliMuridFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'nik', 'pekerjaan', 'alamat', 'profil_lengkap'])]
class WaliMurid extends Model
{
    /** @use HasFactory<WaliMuridFactory> */
    use HasFactory;

    protected $table = 'wali_murid';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profil_lengkap' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Murid, $this, MuridWali>
     */
    public function murid(): BelongsToMany
    {
        return $this->belongsToMany(Murid::class, 'murid_wali')
            ->using(MuridWali::class)
            ->withPivot('hubungan', 'is_kontak_utama', 'created_at');
    }

    /**
     * @return HasMany<Pendaftaran, $this>
     */
    public function pendaftaran(): HasMany
    {
        return $this->hasMany(Pendaftaran::class);
    }
}
