<?php

namespace App\Http\Resources;

use App\Models\Contract;
use App\Models\Department;
use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Contract */
class ContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $salary = $user?->can('viewSalary', $this->resource) ?? false;
        $terms = $this->termsAt();

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'reference' => $this->reference,
            'type' => $this->type,
            'status' => $this->status(),
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'effective_end_date' => $this->effectiveEndDate()?->toDateString(),
            'trial_end_date' => $this->trial_end_date?->toDateString(),
            'weekly_hours' => $this->weekly_hours,
            'base_salary' => $this->when($salary, fn () => $this->base_salary),
            'position' => $this->position ? ['id' => $this->position->id, 'title' => $this->position->title] : null,
            'department' => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null,
            'collective_agreement' => $this->collective_agreement,
            'description' => $this->description,
            'current_terms' => [
                'weekly_hours' => $terms['weekly_hours'],
                'base_salary' => $this->when($salary, fn () => $terms['base_salary']),
                'position' => $terms['position_id'] ? Position::find($terms['position_id'])?->title : null,
                'department' => $terms['department_id'] ? Department::find($terms['department_id'])?->name : null,
            ],
            'ended_at' => $this->ended_at?->toDateString(),
            'end_reason' => $this->end_reason,
            'end_reason_label' => $this->end_reason ? Contract::END_REASONS[$this->end_reason] : null,
            'end_notes' => $this->end_notes,
            'has_document' => $this->document_path !== null,
            'amendments' => $this->amendments->map(fn (Contract $a) => [
                'id' => $a->id,
                'reference' => $a->reference,
                'start_date' => $a->start_date->toDateString(),
                'applied' => $a->start_date->isPast() || $a->start_date->isToday(),
                'description' => $a->description,
                'has_document' => $a->document_path !== null,
                'changes' => array_filter([
                    'end_date' => $a->end_date?->toDateString(),
                    'weekly_hours' => $a->weekly_hours,
                    'base_salary' => $salary ? $a->base_salary : null,
                    'position' => $a->position?->title,
                    'department' => $a->department?->name,
                ], fn ($v) => $v !== null),
            ])->values(),
            'can' => [
                'manage' => $user?->can('manage', Contract::class) ?? false,
                'documents' => $user?->can('viewDocuments', $this->resource) ?? false,
            ],
        ];
    }
}
