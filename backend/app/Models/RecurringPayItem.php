<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringPayItem extends Model
{
    protected $fillable = ['employee_id', 'payroll_rubric_id', 'amount', 'start_month', 'end_month', 'comment'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(PayrollRubric::class, 'payroll_rubric_id');
    }

    /** Éléments en vigueur un mois donné (AAAA-MM). */
    public function scopeActiveIn(Builder $query, string $month): Builder
    {
        return $query->where('start_month', '<=', $month)
            ->where(fn ($q) => $q->whereNull('end_month')->orWhere('end_month', '>=', $month));
    }
}
