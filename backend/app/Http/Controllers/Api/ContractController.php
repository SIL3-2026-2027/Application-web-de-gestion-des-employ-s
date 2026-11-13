<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Models\Employee;
use App\Services\ContractService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ContractController extends Controller
{
    private const RELATIONS = ['position', 'department', 'amendments.position', 'amendments.department'];

    public function __construct(private readonly ContractService $service) {}

    public function index(Employee $employee): AnonymousResourceCollection
    {
        $this->authorize('view', $employee);

        return ContractResource::collection($employee->contracts()->with(self::RELATIONS)->get());
    }

    public function store(Request $request, Employee $employee): ContractResource
    {
        $this->authorize('manage', Contract::class);

        $contract = $this->service->create($request->user(), $employee, $this->validateContract($request));

        return new ContractResource($contract->load(self::RELATIONS));
    }

    public function update(Request $request, Contract $contract): ContractResource
    {
        $this->authorize('manage', Contract::class);
        abort_if($contract->isAmendment(), 404);

        return new ContractResource($this->service->update($contract, $this->validateContract($request))->load(self::RELATIONS));
    }

    public function destroy(Contract $contract): Response
    {
        $this->authorize('manage', Contract::class);
        abort_if($contract->ended_at !== null, 422, 'Un contrat terminé est conservé dans le dossier.');

        if ($contract->document_path) {
            Storage::disk('local')->delete($contract->document_path);
        }
        $contract->delete(); // supprime aussi les avenants (cascade)

        return response()->noContent();
    }

    public function amend(Request $request, Contract $contract): ContractResource
    {
        $this->authorize('manage', Contract::class);
        abort_if($contract->isAmendment(), 404);

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'weekly_hours' => ['nullable', 'numeric', 'min:1', 'max:48'],
            'base_salary' => ['nullable', 'numeric', 'min:0'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [], $this->attributeNames() + ['start_date' => "date d'effet"]);

        $this->service->amend($request->user(), $contract, $data);

        return new ContractResource($contract->fresh()->load(self::RELATIONS));
    }

    public function end(Request $request, Contract $contract): ContractResource
    {
        $this->authorize('manage', Contract::class);
        abort_if($contract->isAmendment(), 404);

        $data = $request->validate([
            'ended_at' => ['required', 'date'],
            'end_reason' => ['required', Rule::in(array_keys(Contract::END_REASONS))],
            'end_notes' => ['nullable', 'string', 'max:2000'],
            'archive_employee' => ['boolean'],
        ], [], ['ended_at' => 'date de fin', 'end_reason' => 'motif']);

        return new ContractResource($this->service->end($contract, $data)->load(self::RELATIONS));
    }

    /** CTR-04 : fins de contrat et de période d'essai dans les N prochains jours. */
    public function expiring(Request $request)
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']]);
        $employees = Employee::query()->visibleTo($request->user());

        return array_map(fn (array $d) => [
            'kind' => $d['kind'],
            'date' => $d['date']->toDateString(),
            'days_left' => $d['days_left'],
            'contract_id' => $d['contract']->id,
            'reference' => $d['contract']->reference,
            'type' => $d['contract']->type,
            'employee' => ['id' => $d['contract']->employee->id, 'full_name' => $d['contract']->employee->full_name],
        ], $this->service->upcomingDeadlines($employees, $request->integer('days', 30)));
    }

    // ---------- Documents ----------

    /** CTR-02 : contrat ou avenant au format PDF. */
    public function pdf(Contract $contract)
    {
        $this->authorize('viewDocuments', $contract);
        $contract->load(['employee.site', 'position', 'department', 'contract']);

        $view = $contract->isAmendment() ? 'pdf.amendment' : 'pdf.contract';

        return $this->renderPdf($view, $contract)->download("{$contract->reference}.pdf");
    }

    /** CTR-06 : certificat de travail d'un contrat terminé. */
    public function certificate(Contract $contract)
    {
        $this->authorize('viewDocuments', $contract);
        abort_if($contract->isAmendment(), 404);
        abort_unless($contract->ended_at || $contract->status() === 'termine', 422, 'Le certificat de travail est disponible à la fin du contrat.');

        $contract->load(['employee', 'amendments.position', 'position']);

        return $this->renderPdf('pdf.certificate', $contract)->download("certificat-travail-{$contract->employee->matricule}.pdf");
    }

    private function renderPdf(string $view, Contract $contract): \Barryvdh\DomPDF\PDF
    {
        // N'embarque que les caractères utilisés de la police : fichiers ~20 fois plus légers
        return Pdf::loadView($view, ['contract' => $contract, 'company' => config('company')])
            ->setOption('isFontSubsettingEnabled', true);
    }

    /** Dépôt de l'exemplaire signé (scan). */
    public function uploadDocument(Request $request, Contract $contract): ContractResource
    {
        $this->authorize('manage', Contract::class);
        $request->validate(['document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']], [], ['document' => 'document']);

        if ($contract->document_path) {
            Storage::disk('local')->delete($contract->document_path);
        }
        $contract->update(['document_path' => $request->file('document')->store("contracts/{$contract->employee_id}", 'local')]);

        $main = $contract->isAmendment() ? $contract->contract : $contract;

        return new ContractResource($main->fresh()->load(self::RELATIONS));
    }

    public function document(Contract $contract)
    {
        $this->authorize('viewDocuments', $contract);
        abort_unless($contract->document_path && Storage::disk('local')->exists($contract->document_path), 404);

        return Storage::disk('local')->download($contract->document_path, "{$contract->reference}-signe.".pathinfo($contract->document_path, PATHINFO_EXTENSION));
    }

    private function validateContract(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(Contract::TYPES)],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'trial_end_date' => ['nullable', 'date'],
            'weekly_hours' => ['required', 'numeric', 'min:1', 'max:48'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'collective_agreement' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [], $this->attributeNames());
    }

    private function attributeNames(): array
    {
        return [
            'type' => 'type de contrat',
            'start_date' => 'date de début',
            'end_date' => 'date de fin',
            'trial_end_date' => "fin de période d'essai",
            'weekly_hours' => 'durée hebdomadaire',
            'base_salary' => 'salaire',
            'position_id' => 'poste',
            'department_id' => 'département',
        ];
    }
}
