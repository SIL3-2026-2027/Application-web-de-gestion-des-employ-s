<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    protected $fillable = ['payroll_period_id', 'employee_id', 'payroll_rubric_id', 'quantity', 'amount', 'source', 'comment', 'created_by'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'amount' => 'float'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(PayrollRubric::class, 'payroll_rubric_id');
    }
}
