<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class DashboardRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Hanya untuk wali murid: anak yang ditampilkan. */
            'murid_id' => ['sometimes', 'integer'],
        ];
    }

    public function muridId(): ?int
    {
        return $this->filled('murid_id') ? $this->integer('murid_id') : null;
    }
}
