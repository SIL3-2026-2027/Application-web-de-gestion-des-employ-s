<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Contract extends Model
{
    use LogsActivity;

    public const TYPES = ['CDI', 'CDD', 'stage', 'alternance', 'interim', 'freelance'];

    /** Types à durée déterminée : date de fin obligatoire. */
    public const FIXED_TERM_TYPES = ['CDD', 'stage', 'alternance', 'interim', 'freelance'];

    public const END_REASONS = [
        'demission' => 'Démission',
        'licenciement' => 'Licenciement',
        'fin_cdd' => 'Fin de CDD',
        'rupture_conventionnelle' => 'Rupture conventionnelle',
        'retraite' => 'Départ à la retraite',
        'fin_periode_essai' => "Rupture de la période d'essai",
        'fin_stage' => 'Fin de stage',
        'autre' => 'Autre',
    ];

    /** Conditions qu'un avenant peut modifier. */
    public const AMENDABLE = ['end_date', 'weekly_hours', 'base_salary', 'position_id', 'department_id'];

    protected $fillable = [
        'employee_id', 'parent_id', 'kind', 'reference', 'type', 'start_date', 'end_date', 'trial_end_date',
        'weekly_hours', 'base_salary', 'position_id', 'department_id', 'collective_agreement', 'description',
        'applied_at', 'ended_at', 'end_reason', 'end_notes', 'document_path', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'trial_end_date' => 'date',
            'ended_at' => 'date',
            'applied_at' => 'datetime',
            'weekly_hours' => 'float',
            'base_salary' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Contract $contract) {
            $contract->kind ??= 'contrat';
            $contract->reference ??= static::nextReference($contract->kind);
        });
    }

    /** CTR-2026-0001 pour un contrat, AV-2026-0001 pour un avenant. */
    public static function nextReference(string $kind): string
    {
        $prefix = ($kind === 'avenant' ? 'AV' : 'CTR').'-'.now()->year.'-';
        $last = static::query()->where('reference', 'like', $prefix.'%')->orderByDesc('reference')->value('reference');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['base_salary', 'applied_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('contract');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'parent_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(Contract::class, 'parent_id')->orderBy('start_date')->orderBy('id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function scopeMain(Builder $query): Builder
    {
        return $query->where('kind', 'contrat');
    }

    public function isAmendment(): bool
    {
        return $this->kind === 'avenant';
    }

    /**
     * Conditions en vigueur à une date : le contrat, puis chaque avenant déjà effectif par-dessus.
     *
     * @return array{end_date: ?string, weekly_hours: ?float, base_salary: ?float, position_id: ?int, department_id: ?int}
     */
    public function termsAt(?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::today();
        $terms = [];
        foreach (self::AMENDABLE as $field) {
            $terms[$field] = $this->{$field};
        }

        foreach ($this->amendments as $amendment) {
            if ($amendment->start_date->gt($date)) {
                break;
            }
            foreach (self::AMENDABLE as $field) {
                if ($amendment->{$field} !== null) {
                    $terms[$field] = $amendment->{$field};
                }
            }
        }

        $terms['end_date'] = $terms['end_date'] instanceof \DateTimeInterface ? $terms['end_date']->format('Y-m-d') : $terms['end_date'];

        return $terms;
    }

    /** Date de fin réelle : rupture, sinon dernière date de fin prévue (avenants compris, même futurs). */
    public function effectiveEndDate(): ?CarbonImmutable
    {
        if ($this->ended_at) {
            return CarbonImmutable::parse($this->ended_at);
        }

        $end = $this->end_date;
        foreach ($this->amendments as $amendment) {
            if ($amendment->end_date) {
                $end = $amendment->end_date;
            }
        }

        return $end ? CarbonImmutable::parse($end) : null;
    }

    /** a_venir | en_cours | termine */
    public function status(?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today();

        if ($this->start_date->gt($today)) {
            return 'a_venir';
        }

        $end = $this->effectiveEndDate();

        return $end && $end->lt($today) ? 'termine' : 'en_cours';
    }
}
