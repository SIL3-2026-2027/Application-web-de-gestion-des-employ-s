<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\EmployeeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeImportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $rh;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (User::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->rh = User::factory()->create();
        $this->rh->assignRole(User::ROLE_RH);

        Department::create(['name' => 'Informatique', 'code' => 'IT']);
        Position::create(['title' => 'Développeur']);
    }

    /** Fichier Excel avec les en-têtes du modèle (ou d'autres) et les lignes données. */
    private function xlsx(array $rows, ?array $headers = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers ?? array_values(EmployeeImportService::COLUMNS)));
        foreach ($rows as $row) {
            $values = [];
            foreach (array_keys(EmployeeImportService::COLUMNS) as $key) {
                $values[] = $row[$key] ?? '';
            }
            $writer->addRow(Row::fromValues($headers ? array_values($row) : $values));
        }
        $writer->close();

        return new UploadedFile($path, 'employes.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function valid(array $override = []): array
    {
        return [
            'nom' => 'Dupont', 'prenom' => 'Marie', 'email_pro' => 'marie@entreprise.fr', 'date_embauche' => '01/09/2024',
            'departement' => 'IT', 'poste' => 'Développeur', 'type_contrat' => 'CDI', 'salaire' => '32000', ...$override,
        ];
    }

    public function test_template_download(): void
    {
        $response = $this->actingAs($this->rh)->get('/api/v1/employees/import/template')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();

        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[] = $sheet->getName();
            if (count($sheets) === 1) {
                foreach ($sheet->getRowIterator() as $row) {
                    $this->assertSame('Nom *', $row->toArray()[2]);
                    break;
                }
            }
        }
        $reader->close();

        $this->assertSame(['Employés', 'Valeurs acceptées'], $sheets);
    }

    public function test_preview_reports_errors_line_by_line_without_saving(): void
    {
        Employee::factory()->create(['work_email' => 'deja@entreprise.fr']);

        $result = $this->actingAs($this->rh)->post('/api/v1/employees/import/preview', ['file' => $this->xlsx([
            $this->valid(),
            $this->valid(['nom' => '', 'email_pro' => 'deja@entreprise.fr']),
            $this->valid(['email_pro' => 'x@entreprise.fr', 'date_embauche' => '31/02/2024', 'poste' => 'Astronaute']),
            $this->valid(['email_pro' => 'y@entreprise.fr', 'type_contrat' => 'CDD']),
        ])], ['Accept' => 'application/json'])->assertOk()->json();

        $this->assertSame(1, $result['valid']);
        $this->assertSame(3, $result['invalid']);
        $this->assertSame(2, $result['rows'][0]['line']);
        $this->assertSame([], $result['rows'][0]['errors']);
        $this->assertContains('Nom manquant.', $result['rows'][1]['errors']);
        $this->assertContains("L'e-mail professionnel deja@entreprise.fr est déjà utilisé.", $result['rows'][1]['errors']);
        $this->assertStringContainsString("Date d'embauche invalide", $result['rows'][2]['errors'][0]);
        $this->assertContains('Poste inconnu : « Astronaute ».', $result['rows'][2]['errors']);
        $this->assertContains('Fin du contrat obligatoire pour un CDD.', $result['rows'][3]['errors']);

        $this->assertSame(1, Employee::count()); // rien d'enregistré
    }

    public function test_import_is_all_or_nothing_unless_only_valid(): void
    {
        $file = fn () => $this->xlsx([$this->valid(), $this->valid(['email_pro' => 'pas-un-email'])]);

        $this->actingAs($this->rh)->post('/api/v1/employees/import', ['file' => $file()], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('file');
        $this->assertSame(0, Employee::count());

        $this->actingAs($this->rh)->post('/api/v1/employees/import', ['file' => $file(), 'only_valid' => '1'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJson(['created' => 1, 'skipped' => 1, 'contracts' => 1]);

        $marie = Employee::where('work_email', 'marie@entreprise.fr')->first();
        $this->assertSame('2024-09-01', $marie->hire_date->toDateString());
        $this->assertSame('Informatique', $marie->department->name);
        $this->assertMatchesRegularExpression('/^EMP\d{5}$/', $marie->matricule);
        $this->assertSame(32000.0, Contract::where('employee_id', $marie->id)->first()->base_salary);
    }

    public function test_managers_from_same_file_and_missing_references_created(): void
    {
        $result = $this->actingAs($this->rh)->post('/api/v1/employees/import', [
            'file' => $this->xlsx([
                $this->valid(['matricule' => 'A100', 'email_pro' => 'chef@entreprise.fr', 'departement' => 'Logistique', 'poste' => 'Chef d’équipe']),
                $this->valid(['matricule' => 'A101', 'email_pro' => 'marie2@entreprise.fr', 'manager' => 'A100', 'site' => 'Entrepôt Nord', 'civilite' => 'Madame', 'statut' => "période d'essai"]),
            ]),
            'create_missing' => '1',
        ], ['Accept' => 'application/json'])->assertCreated()->json();

        $this->assertSame(2, $result['created']);
        $chef = Employee::where('matricule', 'A100')->first();
        $marie = Employee::where('matricule', 'A101')->first();
        $this->assertSame($chef->id, $marie->manager_id);
        $this->assertSame('Mme', $marie->civility);
        $this->assertSame('periode_essai', $marie->status);
        $this->assertSame('Logistique', $chef->department->name);
        $this->assertSame('Entrepôt Nord', $marie->site->name);
    }

    public function test_headers_in_any_order_and_dates_as_excel_values(): void
    {
        $result = $this->actingAs($this->rh)->post('/api/v1/employees/import/preview', [
            'file' => $this->xlsx(
                [['Marie', 'DUPONT', new \DateTimeImmutable('2024-09-01'), 75002]],
                ['prénom', 'NOM', "Date d'embauche", 'Code postal'],
            ),
        ], ['Accept' => 'application/json'])->assertOk()->json();

        $this->assertSame([], $result['rows'][0]['errors']);
        $this->assertSame('2024-09-01', $result['rows'][0]['data']['date_embauche']);
        $this->assertSame('75002', $result['rows'][0]['data']['code_postal']);
    }

    public function test_missing_required_columns(): void
    {
        $this->actingAs($this->rh)->post('/api/v1/employees/import/preview', [
            'file' => $this->xlsx([['Marie']], ['Prénom']),
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
    }

    public function test_french_excel_csv_with_semicolons_and_windows_encoding(): void
    {
        $csv = mb_convert_encoding("Nom;Prénom;Date d'embauche;Poste\nLefèvre;Hélène;15/01/2025;Développeur\n", 'Windows-1252', 'UTF-8');
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $csv);

        $result = $this->actingAs($this->rh)->post('/api/v1/employees/import/preview', [
            'file' => new UploadedFile($path, 'employes.csv', 'text/csv', null, true),
        ], ['Accept' => 'application/json'])->assertOk()->json();

        $this->assertSame([], $result['rows'][0]['errors']);
        $this->assertSame('Hélène', $result['rows'][0]['data']['prenom']);
    }

    public function test_only_hr_can_import(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(User::ROLE_MANAGER);

        $this->actingAs($manager)->post('/api/v1/employees/import', ['file' => $this->xlsx([$this->valid()])], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($manager)->get('/api/v1/employees/import/template')->assertForbidden();
    }

    public function test_export_respects_filters_and_scope(): void
    {
        $it = Department::first();
        Employee::factory()->create(['last_name' => 'Alpha', 'department_id' => $it->id, 'status' => 'actif']);
        Employee::factory()->create(['last_name' => 'Beta', 'status' => 'actif']);

        foreach (['xlsx', 'csv', 'pdf'] as $format) {
            $this->actingAs($this->rh)->get("/api/v1/employees/export?format={$format}")->assertOk()->assertDownload("employes-".now()->format('Y-m-d').".{$format}");
        }

        $response = $this->actingAs($this->rh)->get("/api/v1/employees/export?format=csv&department_id={$it->id}")->assertOk();
        $content = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content); // BOM pour Excel
        $this->assertStringContainsString('Alpha', $content);
        $this->assertStringNotContainsString('Beta', $content);
        $this->assertStringContainsString('Matricule;Civilité;Nom', $content);

        // Un manager n'exporte que son équipe
        $manager = User::factory()->create();
        $manager->assignRole(User::ROLE_MANAGER);
        $boss = Employee::factory()->create(['user_id' => $manager->id, 'last_name' => 'Chef']);
        Employee::factory()->create(['last_name' => 'Gamma', 'manager_id' => $boss->id]);
        $content = file_get_contents($this->actingAs($manager)->get('/api/v1/employees/export?format=csv')->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Gamma', $content);
        $this->assertStringNotContainsString('Alpha', $content);
    }
}
