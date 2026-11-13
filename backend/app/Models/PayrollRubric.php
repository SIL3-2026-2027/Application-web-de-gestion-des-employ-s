<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRubric extends Model
{
    /** Rubriques alimentées automatiquement (ne pas supprimer). */
    public const OVERTIME_25 = 'HS25';
    public const OVERTIME_50 = 'HS50';
    public const UNJUSTIFIED_ABSENCE = 'ABS_NJ';

    protected $fillable = ['code', 'label', 'kind', 'unit', 'export_code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function recurringItems(): HasMany
    {
        return $this->hasMany(RecurringPayItem::class);
    }
}
