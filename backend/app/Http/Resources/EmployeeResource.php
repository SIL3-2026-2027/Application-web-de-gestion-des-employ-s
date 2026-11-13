<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Employee */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sensitive = $request->user()?->can('viewSensitive', $this->resource) ?? false;

        return [
            'id' => $this->id,
            'matricule' => $this->matricule,
            'civility' => $this->civility,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'full_name' => $this->full_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'birth_place' => $this->birth_place,
            'nationality' => $this->nationality,
            'marital_status' => $this->marital_status,
            'children_count' => $this->children_count,
            'personal_email' => $this->personal_email,
            'work_email' => $this->work_email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'social_security_number' => $this->when($sensitive, fn () => $this->social_security_number),
            'iban' => $this->when($sensitive, fn () => $this->iban),

            'department_id' => $this->department_id,
            'position_id' => $this->position_id,
            'site_id' => $this->site_id,
            'manager_id' => $this->manager_id,
            'work_schedule_id' => $this->work_schedule_id,
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'position' => $this->whenLoaded('position', fn () => $this->position ? ['id' => $this->position->id, 'title' => $this->position->title] : null),
            'site' => $this->whenLoaded('site', fn () => $this->site ? ['id' => $this->site->id, 'name' => $this->site->name] : null),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? ['id' => $this->manager->id, 'full_name' => $this->manager->full_name] : null),

            'contract' => $this->whenLoaded('currentContract', fn () => $this->currentContract ? [
                'id' => $this->currentContract->id,
                'type' => $this->currentContract->type,
                'end_date' => $this->currentContract->effectiveEndDate()?->toDateString(),
            ] : null),
            'hire_date' => $this->hire_date?->toDateString(),
            'status' => $this->status,
            'exit_date' => $this->exit_date?->toDateString(),
            'exit_reason' => $this->exit_reason,
            'has_account' => $this->user_id !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
