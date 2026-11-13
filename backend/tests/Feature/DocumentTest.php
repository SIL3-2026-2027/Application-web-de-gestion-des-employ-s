<?php

namespace Tests\Feature;

use App\Models\CompanyDocument;
use App\Models\Contract;
use App\Models\DocumentCategory;
use App\Models\DocumentRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Notifications\SimpleNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $rh;
    private User $manager;
    private User $employee;
    private User $direction;
    private DocumentCategory $identity;
    private DocumentCategory $medical;
    private DocumentCategory $training;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-10-01 09:00:00');

        foreach (User::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->identity = DocumentCategory::create(['name' => "Pièce d'identité", 'code' => 'IDENTITE', 'has_expiry' => true]);
        $this->medical = DocumentCategory::create(['name' => 'Arrêts de travail', 'code' => 'ARRET', 'visible_to_employee' => false]);
        $this->training = DocumentCategory::create(['name' => 'Habilitations', 'code' => 'FORMATION', 'visible_to_manager' => true]);

        $this->rh = $this->userWithRole(User::ROLE_RH);
        $this->manager = $this->userWithRole(User::ROLE_MANAGER);
        $this->direction = $this->userWithRole(User::ROLE_DIRECTION);
        $this->employee = $this->userWithRole(User::ROLE_EMPLOYE, ['manager_id' => $this->manager->employee->id, 'hire_date' => '2022-03-01', 'status' => 'actif']);
    }

    private function userWithRole(string $role, array $employee = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Employee::factory()->create(['user_id' => $user->id, ...$employee]);

        return $user->fresh();
    }

    private function upload(DocumentCategory $category, array $data = [])
    {
        return $this->actingAs($this->rh)->post("/api/v1/employees/{$this->employee->employee->id}/documents", [
            'document_category_id' => $category->id,
            'file' => UploadedFile::fake()->create('piece.pdf', 300, 'application/pdf'),
            ...$data,
        ], ['Accept' => 'application/json']);
    }

    public function test_hr_uploads_document_with_expiry(): void
    {
        $id = $this->upload($this->identity, ['title' => 'Carte d’identité', 'expires_at' => '2030-05-01'])
            ->assertCreated()
            ->assertJsonPath('expires_at', '2030-05-01')
            ->json('id');

        Storage::disk('local')->assertExists(EmployeeDocument::find($id)->file_path);
    }

    public function test_file_type_and_size_are_checked(): void
    {
        $this->actingAs($this->rh)->post("/api/v1/employees/{$this->employee->employee->id}/documents", [
            'document_category_id' => $this->identity->id,
            'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'),
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');

        $this->actingAs($this->rh)->post("/api/v1/employees/{$this->employee->employee->id}/documents", [
            'document_category_id' => $this->identity->id,
            'file' => UploadedFile::fake()->create('gros.pdf', 11000, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
    }

    public function test_only_hr_uploads(): void
    {
        $this->actingAs($this->employee)->post("/api/v1/employees/{$this->employee->employee->id}/documents", [
            'document_category_id' => $this->identity->id,
            'file' => UploadedFile::fake()->create('piece.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_visibility_depends_on_category_and_role(): void
    {
        $identity = $this->upload($this->identity)->json('id');
        $medical = $this->upload($this->medical)->json('id');
        $training = $this->upload($this->training)->json('id');
        $url = "/api/v1/employees/{$this->employee->employee->id}/documents";

        // RH : tout
        $this->actingAs($this->rh)->getJson($url)->assertJsonCount(3);
        // Employé : ses documents, sauf catégories masquées (arrêts de travail ici)
        $this->actingAs($this->employee)->getJson($url)->assertJsonCount(2);
        $this->actingAs($this->employee)->get("/api/v1/documents/{$identity}/download")->assertOk();
        $this->actingAs($this->employee)->get("/api/v1/documents/{$medical}/download")->assertForbidden();
        // Manager : seulement les catégories ouvertes aux managers
        $this->actingAs($this->manager)->getJson($url)->assertJsonCount(1)->assertJsonPath('0.id', $training);
        $this->actingAs($this->manager)->get("/api/v1/documents/{$identity}/download")->assertForbidden();
        // Direction et autres employés : aucun accès
        $this->actingAs($this->direction)->getJson($url)->assertForbidden();
        $this->actingAs($this->userWithRole(User::ROLE_EMPLOYE))->get("/api/v1/documents/{$identity}/download")->assertForbidden();
    }

    public function test_deleting_document_removes_file(): void
    {
        $id = $this->upload($this->identity)->json('id');
        $path = EmployeeDocument::find($id)->file_path;

        $this->actingAs($this->rh)->deleteJson("/api/v1/documents/{$id}")->assertNoContent();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_hr_generates_attestations(): void
    {
        $this->actingAs($this->rh)->postJson("/api/v1/employees/{$this->employee->employee->id}/documents/generate", ['type' => 'attestation_salaire'])
            ->assertJsonValidationErrors('type'); // pas de contrat : pas de salaire connu

        Contract::create(['employee_id' => $this->employee->employee->id, 'type' => 'CDI', 'start_date' => '2022-03-01', 'weekly_hours' => 35, 'base_salary' => 42000]);

        foreach (['attestation_travail', 'attestation_salaire'] as $type) {
            $this->actingAs($this->rh)->postJson("/api/v1/employees/{$this->employee->employee->id}/documents/generate", ['type' => $type])
                ->assertCreated()
                ->assertJsonPath('source', 'genere')
                ->assertJsonPath('category.name', 'Attestations')
                ->assertJsonPath('mime_type', 'application/pdf');
        }

        $this->actingAs($this->employee)->getJson("/api/v1/employees/{$this->employee->employee->id}/documents")->assertJsonCount(2);
    }

    public function test_request_and_fulfil_attestation(): void
    {
        Notification::fake();

        $this->actingAs($this->employee)->postJson('/api/v1/document-requests', ['type' => 'attestation_travail'])->assertCreated();
        $this->actingAs($this->employee)->postJson('/api/v1/document-requests', ['type' => 'attestation_travail'])->assertJsonValidationErrors('type');
        Notification::assertSentTo($this->rh, SimpleNotification::class, fn ($n) => str_contains($n->titleText, 'Demande de document'));

        $request = DocumentRequest::first();
        $this->actingAs($this->employee)->postJson("/api/v1/document-requests/{$request->id}/fulfill")->assertForbidden();
        $this->actingAs($this->rh)->getJson('/api/v1/document-requests?scope=all&status=en_attente')->assertJsonCount(1);

        $this->actingAs($this->rh)->postJson("/api/v1/document-requests/{$request->id}/fulfill")->assertOk();
        Notification::assertSentTo($this->employee, SimpleNotification::class, fn ($n) => $n->titleText === 'Votre document est disponible');

        $this->actingAs($this->employee)->getJson('/api/v1/document-requests')
            ->assertJsonPath('0.status', 'traitee')
            ->assertJsonPath('0.document.title', 'Attestation de travail du 01/10/2026');

        $this->actingAs($this->rh)->postJson("/api/v1/document-requests/{$request->id}/refuse", ['reason' => 'x'])->assertJsonValidationErrors('status');
    }

    public function test_other_document_request_needs_upload_and_can_be_refused(): void
    {
        $this->actingAs($this->employee)->postJson('/api/v1/document-requests', ['type' => 'autre'])->assertJsonValidationErrors('comment');
        $this->actingAs($this->employee)->postJson('/api/v1/document-requests', ['type' => 'autre', 'comment' => 'Attestation Pôle emploi'])->assertCreated();
        $request = DocumentRequest::first();

        $this->actingAs($this->rh)->postJson("/api/v1/document-requests/{$request->id}/fulfill")->assertJsonValidationErrors('file');
        $this->actingAs($this->rh)->postJson("/api/v1/document-requests/{$request->id}/refuse", ['reason' => 'Pas applicable'])->assertOk();
        $this->actingAs($this->employee)->getJson('/api/v1/document-requests')->assertJsonPath('0.refusal_reason', 'Pas applicable');
    }

    public function test_company_documents_and_acknowledgments(): void
    {
        Notification::fake();

        $this->actingAs($this->employee)->post('/api/v1/company-documents', ['title' => 'X', 'file' => UploadedFile::fake()->create('r.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertForbidden();

        $id = $this->actingAs($this->rh)->post('/api/v1/company-documents', [
            'title' => 'Règlement intérieur',
            'file' => UploadedFile::fake()->create('reglement.pdf', 100, 'application/pdf'),
            'requires_acknowledgment' => '1',
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');

        Notification::assertSentTo($this->employee, SimpleNotification::class);
        Notification::assertNotSentTo($this->rh, SimpleNotification::class);

        $this->actingAs($this->employee)->getJson('/api/v1/company-documents')->assertJsonPath('0.acknowledged_at', null)->assertJsonPath('0.readers_count', null);
        $this->actingAs($this->employee)->get("/api/v1/company-documents/{$id}/download")->assertOk();
        $this->actingAs($this->employee)->postJson("/api/v1/company-documents/{$id}/acknowledge")->assertOk();
        $this->actingAs($this->employee)->postJson("/api/v1/company-documents/{$id}/acknowledge")->assertOk(); // idempotent

        $this->actingAs($this->rh)->getJson('/api/v1/company-documents')->assertJsonPath('0.readers_count', 1)->assertJsonPath('0.active_users_count', 4);
        $readers = $this->actingAs($this->rh)->getJson("/api/v1/company-documents/{$id}/readers")->assertOk()->json();
        $this->assertNotNull(collect($readers)->firstWhere('id', $this->employee->id)['acknowledged_at']);
        $this->assertNull(collect($readers)->firstWhere('id', $this->manager->id)['acknowledged_at']);

        $this->assertSame(1, CompanyDocument::find($id)->readers()->count());
    }

    public function test_expiring_documents_listed_and_notified(): void
    {
        Notification::fake();
        $this->upload($this->identity, ['expires_at' => '2026-10-08']); // J-7
        $this->upload($this->identity, ['expires_at' => '2026-10-20']); // J-19 : listé, pas notifié
        $this->upload($this->identity, ['expires_at' => '2027-10-20']);

        $this->actingAs($this->rh)->getJson('/api/v1/documents/expiring?days=30')
            ->assertJsonCount(2)
            ->assertJsonPath('0.days_left', 7);
        $this->actingAs($this->manager)->getJson('/api/v1/documents/expiring')->assertForbidden();

        $this->artisan('notifications:document-expiry')->expectsOutputToContain('1 document(s)');
        Notification::assertSentTo($this->rh, SimpleNotification::class, fn ($n) => str_contains($n->titleText, 'expire dans 7 jours'));
    }

    public function test_categories_management(): void
    {
        $this->actingAs($this->rh)->postJson('/api/v1/document-categories', ['name' => 'Mutuelle', 'code' => 'MUTUELLE'])->assertCreated();
        $this->upload($this->identity);
        $this->actingAs($this->rh)->deleteJson("/api/v1/document-categories/{$this->identity->id}")->assertStatus(422);
        $this->actingAs($this->manager)->postJson('/api/v1/document-categories', ['name' => 'X', 'code' => 'X'])->assertForbidden();
    }
}
