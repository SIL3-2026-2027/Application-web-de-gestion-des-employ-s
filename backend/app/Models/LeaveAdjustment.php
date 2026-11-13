<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveAdjustment extends Model
{
    protected $fillable = ['employee_id', 'leave_type_id', 'year', 'days', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['days' => 'float', 'year' => 'integer'];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
