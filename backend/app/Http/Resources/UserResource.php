<?php

namespace App\Http\Resources;

use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'roles' => $this->getRoleNames(),
            'employee_id' => $this->employee?->id,
            'employee' => $this->employee ? [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'matricule' => $this->employee->matricule,
                'position' => $this->employee->position?->title,
                'department' => $this->employee->department?->name,
            ] : null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'can_manage' => $actor && $actor->isHr() && $actor->id !== $this->id
                ? app(AccountService::class)->canManage($actor, $this->resource)
                : false,
        ];
    }
}
