<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveRequest extends Model
{
    use HasFactory, LogsActivity;

    public const PENDING = 'en_attente';
    public const MANAGER_APPROVED = 'valide_manager';
    public const APPROVED = 'approuve';
    public const REFUSED = 'refuse';
    public const CANCELLED = 'annule';

    /** Statuts qui « occupent » les dates et réservent du solde. */
    public const ACTIVE_STATUSES = [self::PENDING, self::MANAGER_APPROVED, self::APPROVED];
    public const AWAITING_STATUSES = [self::PENDING, self::MANAGER_APPROVED];

    protected $fillable = [
        'employee_id', 'leave_type_id', 'start_date', 'end_date', 'start_half_day', 'end_half_day',
        'days', 'reason', 'attachment_path', 'status', 'created_by',
        'manager_decision_by', 'manager_decided_at', 'hr_decision_by', 'hr_decided_at', 'refusal_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'start_half_day' => 'boolean',
            'end_half_day' => 'boolean',
            'days' => 'float',
            'manager_decided_at' => 'datetime',
            'hr_decided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'start_date', 'end_date', 'days', 'refusal_reason'])
            ->logOnlyDirty()
            ->useLogName('leave');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function managerDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_decision_by');
    }

    public function hrDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_decision_by');
    }

    public function isAwaiting(): bool
    {
        return in_array($this->status, self::AWAITING_STATUSES, true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /** Demandes chevauchant la période [from, to]. */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->where('start_date', '<=', $to)->where('end_date', '>=', $from);
    }

    /** Demandes des employés visibles par l'utilisateur (soi, son équipe, ou tout le monde). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('employee_id', Employee::query()->visibleTo($user)->select('id'));
    }
}
