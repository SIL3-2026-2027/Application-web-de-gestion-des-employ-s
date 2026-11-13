<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LeaveRequest */
class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'matricule' => $this->employee->matricule,
            ]),
            'leave_type' => $this->whenLoaded('leaveType', fn () => [
                'id' => $this->leaveType->id,
                'name' => $this->leaveType->name,
                'code' => $this->leaveType->code,
                'color' => $this->leaveType->color,
            ]),
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'start_half_day' => $this->start_half_day,
            'end_half_day' => $this->end_half_day,
            'days' => $this->days,
            'reason' => $this->reason,
            'has_attachment' => $this->attachment_path !== null,
            'status' => $this->status,
            'refusal_reason' => $this->refusal_reason,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'manager_decision' => $this->manager_decided_at ? [
                'by' => $this->whenLoaded('managerDecider', fn () => $this->managerDecider?->name),
                'at' => $this->manager_decided_at->toIso8601String(),
            ] : null,
            'hr_decision' => $this->hr_decided_at ? [
                'by' => $this->whenLoaded('hrDecider', fn () => $this->hrDecider?->name),
                'at' => $this->hr_decided_at->toIso8601String(),
            ] : null,
            'created_at' => $this->created_at->toIso8601String(),
            'can' => [
                'decide' => $user?->can('decide', $this->resource) ?? false,
                'cancel' => $user?->can('cancel', $this->resource) ?? false,
            ],
        ];
    }
}
