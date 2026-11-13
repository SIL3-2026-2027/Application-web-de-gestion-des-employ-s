<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\Request;

/** Tableau de bord (RAP-01 / RAP-02), limité au périmètre visible de l'utilisateur. */
class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $visible = fn () => Employee::query()->visibleTo($user);

        $monthStart = now()->startOfMonth();

        return [
            'headcount' => $visible()->where('status', '!=', 'sorti')->count(),
            'on_probation' => $visible()->where('status', 'periode_essai')->count(),
            'hires_this_month' => $visible()->where('hire_date', '>=', $monthStart)->count(),
            'exits_this_month' => $visible()->where('status', 'sorti')->where('exit_date', '>=', $monthStart)->count(),
            'by_department' => $user->canViewAllEmployees()
                ? Department::withCount(['employees' => fn ($q) => $q->where('status', '!=', 'sorti')])
                    ->orderByDesc('employees_count')
                    ->get(['id', 'name'])
                    ->map(fn ($d) => ['name' => $d->name, 'count' => $d->employees_count])
                : [],
            'recent_hires' => $visible()
                ->with('position:id,title')
                ->where('status', '!=', 'sorti')
                ->latest('hire_date')
                ->limit(5)
                ->get()
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'full_name' => $e->full_name,
                    'position' => $e->position?->title,
                    'hire_date' => $e->hire_date->toDateString(),
                ]),
        ];
    }
}
