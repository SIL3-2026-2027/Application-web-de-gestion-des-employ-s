<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Employee extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUSES = ['actif', 'periode_essai', 'suspendu', 'sorti'];

    protected $fillable = [
        'matricule', 'user_id',
        'civility', 'last_name', 'first_name', 'birth_date', 'birth_place', 'nationality',
        'social_security_number', 'marital_status', 'children_count', 'photo_path',
        'personal_email', 'work_email', 'phone', 'address', 'city', 'postal_code',
        'emergency_contact_name', 'emergency_contact_phone', 'iban',
        'department_id', 'position_id', 'site_id', 'manager_id', 'work_schedule_id',
        'hire_date', 'status', 'exit_date', 'exit_reason',
    ];

    protected $hidden = ['social_security_number', 'iban'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'hire_date' => 'date',
            'exit_date' => 'date',
            'children_count' => 'integer',
            'social_security_number' => 'encrypted',
            'iban' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Employee $employee) {
            $employee->matricule ??= static::nextMatricule();
        });
    }

    /** Matricule séquentiel du type EMP00042. */
    public static function nextMatricule(): string
    {
        $last = static::withTrashed()->max('id') ?? 0;

        return sprintf('EMP%05d', $last + 1);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['social_security_number', 'iban'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('employee');
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class)->where('kind', 'contrat')->orderByDesc('start_date');
    }

    /** Dernier contrat commencé (en cours ou le plus récent). */
    public function currentContract(): HasOne
    {
        return $this->hasOne(Contract::class)->ofMany(
            ['start_date' => 'max', 'id' => 'max'],
            fn ($q) => $q->where('kind', 'contrat')->where('start_date', '<=', now()->toDateString()),
        );
    }

    /** Départ de l'employé (EMP-05) : dossier conservé, statut « sorti », compte désactivé. */
    public function markAsLeft(string $exitDate, string $reason): void
    {
        DB::transaction(function () use ($exitDate, $reason) {
            $this->update(['exit_date' => $exitDate, 'exit_reason' => $reason, 'status' => 'sorti']);
            $this->user?->update(['is_active' => false]);
        });
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    /** Horaire de l'employé, ou l'horaire par défaut de l'entreprise. */
    public function effectiveSchedule(): ?WorkSchedule
    {
        return $this->workSchedule ?? WorkSchedule::default();
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveAdjustments(): HasMany
    {
        return $this->hasMany(LeaveAdjustment::class);
    }

    /** L'utilisateur est-il le manager direct de cet employé ? */
    public function isManagedBy(User $user): bool
    {
        return $user->employee !== null && $this->manager_id === $user->employee->id;
    }

    /** Restreint la requête à ce que l'utilisateur a le droit de voir. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canViewAllEmployees()) {
            return $query;
        }

        $employeeId = $user->employee?->id;

        if ($user->hasRole(User::ROLE_MANAGER) && $employeeId) {
            return $query->where(fn ($q) => $q->where('manager_id', $employeeId)->orWhere('id', $employeeId));
        }

        return $query->where('id', $employeeId ?? 0);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('last_name', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('matricule', 'like', "%{$term}%")
                ->orWhere('work_email', 'like', "%{$term}%");
        });
    }
}
