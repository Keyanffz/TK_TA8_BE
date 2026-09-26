<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * @mixin Activity
 */
class LogAktivitasResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pelaku = $this->causer;

        return [
            'id' => $this->id,
            /** Kelompok log, misalnya `pembayaran`, `rapor`, `ppdb`. */
            'jenis' => (string) $this->log_name,
            'event' => $this->event,
            'deskripsi' => $this->description,
            /** Null untuk aksi sistem (scheduler). */
            'pelaku' => $pelaku instanceof User ? ['id' => $pelaku->id, 'nama' => $pelaku->name, 'role' => $pelaku->role] : null,
            'subjek' => $this->subject_type === null ? null : [
                'tipe' => Str::snake(class_basename($this->subject_type)),
                'id' => (int) $this->subject_id,
            ],
            /** @var array<string, mixed> */
            'properti' => $this->properties?->all() ?? [],
            'created_at' => $this->created_at,
        ];
    }
}
