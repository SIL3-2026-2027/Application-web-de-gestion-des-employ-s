<?php

namespace Database\Seeders;

use App\Models\CompanyDocument;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DocumentSeeder extends Seeder
{
    public function run(): void
    {
        $categories = collect([
            ['code' => 'IDENTITE', 'name' => "Pièce d'identité", 'has_expiry' => true],
            ['code' => 'SEJOUR', 'name' => 'Carte de séjour / permis de travail', 'has_expiry' => true],
            ['code' => 'DIPLOME', 'name' => 'Diplômes et certificats'],
            ['code' => 'PERMIS', 'name' => 'Permis de conduire', 'has_expiry' => true],
            ['code' => 'MEDICAL', 'name' => 'Visite médicale', 'has_expiry' => true],
            ['code' => 'ARRET', 'name' => 'Arrêts de travail', 'visible_to_employee' => true],
            ['code' => 'PAIE', 'name' => 'Bulletins de paie'],
            ['code' => 'ATTESTATION', 'name' => 'Attestations'],
            ['code' => 'FORMATION', 'name' => 'Formations et habilitations', 'has_expiry' => true, 'visible_to_manager' => true],
            ['code' => 'AUTRE', 'name' => 'Autres documents'],
        ])->mapWithKeys(fn ($c) => [$c['code'] => DocumentCategory::create($c)]);

        $rh = User::where('email', 'rh@entreprise.test')->first();

        // Quelques pièces de démonstration, dont certaines expirent bientôt (alertes DOC-06)
        foreach (Employee::orderBy('id')->limit(8)->get() as $i => $e) {
            $this->fakeDocument($e, $categories['IDENTITE'], "Carte d'identité", now()->addYears(5)->subDays($i * 40), $rh);
            if ($i % 3 === 0) {
                $this->fakeDocument($e, $categories['MEDICAL'], 'Avis d’aptitude', now()->addDays(7 + $i * 3), $rh);
            }
        }

        $path = 'company-documents/reglement-interieur.pdf';
        Storage::disk('local')->put($path, $this->pdf('Règlement intérieur', 'Version applicable à compter du 1er janvier.'));
        CompanyDocument::create([
            'title' => 'Règlement intérieur',
            'description' => 'Règles d’hygiène, de sécurité et de discipline applicables dans l’entreprise.',
            'file_path' => $path,
            'original_name' => 'reglement-interieur.pdf',
            'mime_type' => 'application/pdf',
            'size' => Storage::disk('local')->size($path),
            'requires_acknowledgment' => true,
            'published_by' => $rh?->id,
        ]);
    }

    private function fakeDocument(Employee $e, DocumentCategory $category, string $title, $expiresAt, ?User $by): void
    {
        $path = "documents/{$e->id}/".str($title)->slug().'.pdf';
        Storage::disk('local')->put($path, $this->pdf($title, "Document de démonstration — {$e->full_name}"));

        $e->documents()->create([
            'document_category_id' => $category->id,
            'title' => $title,
            'file_path' => $path,
            'original_name' => basename($path),
            'mime_type' => 'application/pdf',
            'size' => Storage::disk('local')->size($path),
            'expires_at' => $expiresAt,
            'uploaded_by' => $by?->id,
        ]);
    }

    private function pdf(string $title, string $text): string
    {
        return Pdf::loadHTML("<h1 style='font-family:DejaVu Sans'>{$title}</h1><p style='font-family:DejaVu Sans'>{$text}</p>")
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }
}
