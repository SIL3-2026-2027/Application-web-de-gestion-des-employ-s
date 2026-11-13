<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Position;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Structure de l'entreprise (EMP-10) : départements, postes et sites.
 * Lecture pour tous les utilisateurs connectés, écriture réservée aux RH (middleware de route).
 */
class OrganisationController extends Controller
{
    // ---------- Départements ----------

    public function departments()
    {
        return Department::with(['parent:id,name', 'manager:id,first_name,last_name'])
            ->withCount(['employees' => fn ($q) => $q->where('status', '!=', 'sorti')])
            ->orderBy('name')
            ->get();
    }

    public function storeDepartment(Request $request): Department
    {
        return Department::create($this->validateDepartment($request));
    }

    public function updateDepartment(Request $request, Department $department): Department
    {
        $department->update($this->validateDepartment($request, $department));

        return $department;
    }

    public function destroyDepartment(Department $department): JsonResponse
    {
        abort_if($department->employees()->exists(), 422, 'Ce département contient encore des employés.');
        $department->delete();

        return response()->json(status: 204);
    }

    private function validateDepartment(Request $request, ?Department $department = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20', Rule::unique('departments')->ignore($department)],
            'parent_id' => ['nullable', 'exists:departments,id', Rule::notIn([$department?->id])],
            'manager_id' => ['nullable', 'exists:employees,id'],
        ]);
    }

    // ---------- Postes ----------

    public function positions()
    {
        return Position::withCount('employees')->orderBy('title')->get();
    }

    public function storePosition(Request $request): Position
    {
        return Position::create($this->validatePosition($request));
    }

    public function updatePosition(Request $request, Position $position): Position
    {
        $position->update($this->validatePosition($request));

        return $position;
    }

    public function destroyPosition(Position $position): JsonResponse
    {
        abort_if($position->employees()->exists(), 422, 'Ce poste est encore occupé.');
        $position->delete();

        return response()->json(status: 204);
    }

    private function validatePosition(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'min_salary' => ['nullable', 'numeric', 'min:0'],
            'max_salary' => ['nullable', 'numeric', 'gte:min_salary'],
        ]);
    }

    // ---------- Sites ----------

    public function sites()
    {
        return Site::withCount('employees')->orderBy('name')->get();
    }

    public function storeSite(Request $request): Site
    {
        return Site::create($this->validateSite($request));
    }

    public function updateSite(Request $request, Site $site): Site
    {
        $site->update($this->validateSite($request));

        return $site;
    }

    public function destroySite(Site $site): JsonResponse
    {
        abort_if($site->employees()->exists(), 422, 'Ce site a encore des employés rattachés.');
        $site->delete();

        return response()->json(status: 204);
    }

    private function validateSite(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
        ]);
    }
}
