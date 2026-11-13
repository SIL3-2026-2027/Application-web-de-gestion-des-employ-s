<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $fillable = [
        'name', 'code', 'color', 'is_deductible', 'monthly_accrual', 'annual_allowance',
        'requires_attachment', 'requires_hr_approval', 'employee_can_request', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_deductible' => 'boolean',
            'monthly_accrual' => 'float',
            'annual_allowance' => 'float',
            'requires_attachment' => 'boolean',
            'requires_hr_approval' => 'boolean',
            'employee_can_request' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
