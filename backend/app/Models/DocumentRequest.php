<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequest extends Model
{
    public const TYPES = [
        'attestation_travail' => 'Attestation de travail',
        'attestation_salaire' => 'Attestation de salaire',
        'autre' => 'Autre document',
    ];

    protected $fillable = ['employee_id', 'type', 'comment', 'status', 'employee_document_id', 'refusal_reason', 'handled_by', 'handled_at'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
