<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Site;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/** EMP-07 / RAP-06 : export de la liste filtrée (Excel, CSV, PDF) et modèle d'import. */
class EmployeeExportService
{
    private const STATUS = ['actif' => 'Actif', 'periode_essai' => "Période d'essai", 'suspendu' => 'Suspendu', 'sorti' => 'Sorti'];

    /** Colonnes exportées : aucune donnée sensible (n° CNSS, RIB, salaire). */
    private const HEADERS = [
        'Matricule', 'Civilité', 'Nom', 'Prénom', 'Date de naissance', 'E-mail professionnel', 'Téléphone',
        'Département', 'Poste', 'Site', 'Manager', 'Date d\'embauche', 'Contrat', 'Fin de contrat', 'Statut', 'Date de sortie',
    ];

    public function export(Builder $query, string $format): Response
    {
        $employees = $query->with(['department', 'position', 'site', 'manager', 'currentContract.amendments'])->get();
        $filename = 'employes-'.now()->format('Y-m-d');

        if ($format === 'pdf') {
            return Pdf::loadView('pdf.employees', [
                'employees' => $employees,
                'status' => self::STATUS,
                'company' => config('company'),
            ])->setPaper('a4', 'landscape')->setOption('isFontSubsettingEnabled', true)->download("{$filename}.pdf");
        }

        return $this->spreadsheet($format, $filename, self::HEADERS, $employees->map(fn (Employee $e) => $this->row($e)));
    }

    /** Modèle d'import : en-têtes, une ligne d'exemple, et un onglet avec les valeurs acceptées. */
    public function template(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'modele').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $header = (new Style)->setFontBold()->setBackgroundColor('DEEAF6');
        $text = (new Style)->setFormat('@'); // texte : garde les zéros de « 0197123456 » ou « 01 BP 1234 »

        $writer->getCurrentSheet()->setName('Employés');
        $writer->addRow(Row::fromValues(array_values(EmployeeImportService::COLUMNS), $header));
        $example = [
            '', 'Mme', 'Adjovi', 'Reine', '15/03/1990', 'Abomey', 'Béninoise', 'reine.adjovi@entreprise.bj', '', '0197123456',
            'Lot 512, quartier Fidjrossè', '03 BP 2210', 'Cotonou', Department::value('code') ?? 'RH', Position::value('title') ?? 'Assistant', Site::value('name') ?? 'Siège',
            '', '01/09/2024', 'actif', 'CDI', '', '40', '250000',
        ];
        $writer->addRow(Row::fromValues($example, $text));
        $writer->getCurrentSheet()->setColumnWidth(22, ...range(1, count(EmployeeImportService::COLUMNS)));

        // Onglet d'aide : valeurs acceptées
        $writer->addNewSheetAndMakeItCurrent()->setName('Valeurs acceptées');
        $writer->addRow(Row::fromValues(['Départements (code)', 'Départements (nom)', 'Postes', 'Sites', 'Statuts', 'Types de contrat'], $header));
        $lists = [
            Department::orderBy('name')->pluck('code')->all(),
            Department::orderBy('name')->pluck('name')->all(),
            Position::orderBy('title')->pluck('title')->all(),
            Site::orderBy('name')->pluck('name')->all(),
            ['actif', 'periode_essai'],
            Contract::TYPES,
        ];
        for ($i = 0, $max = max(array_map('count', $lists)); $i < $max; $i++) {
            $writer->addRow(Row::fromValues(array_map(fn ($list) => $list[$i] ?? '', $lists)));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Dates au format JJ/MM/AAAA. Colonnes avec * obligatoires. Matricule vide = attribué automatiquement.']));
        $writer->getCurrentSheet()->setColumnWidth(26, 1, 2, 3, 4, 5, 6);
        $writer->close();

        return response()->download($path, 'modele-import-employes.xlsx')->deleteFileAfterSend();
    }

    private function row(Employee $e): array
    {
        $contract = $e->currentContract;

        return [
            $e->matricule,
            $e->civility,
            $e->last_name,
            $e->first_name,
            $e->birth_date?->format('d/m/Y'),
            $e->work_email,
            $e->phone,
            $e->department?->name,
            $e->position?->title,
            $e->site?->name,
            $e->manager?->full_name,
            $e->hire_date?->format('d/m/Y'),
            $contract?->type,
            $contract?->effectiveEndDate()?->format('d/m/Y'),
            self::STATUS[$e->status] ?? $e->status,
            $e->exit_date?->format('d/m/Y'),
        ];
    }

    private function spreadsheet(string $format, string $filename, array $headers, Collection $rows): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'export').'.'.$format;

        if ($format === 'csv') {
            // Point-virgule et BOM UTF-8 : s'ouvre correctement dans Excel en français
            $options = new CsvOptions;
            $options->FIELD_DELIMITER = ';';
            $options->SHOULD_ADD_BOM = true;
            $writer = new CsvWriter($options);
        } else {
            $writer = new XlsxWriter;
        }

        $writer->openToFile($path);
        if ($writer instanceof XlsxWriter) {
            $writer->getCurrentSheet()->setName('Employés');
        }
        $writer->addRow(Row::fromValues($headers, (new Style)->setFontBold()->setBackgroundColor('DEEAF6')));
        foreach ($rows as $values) {
            $writer->addRow(Row::fromValues(array_map(fn ($v) => $v ?? '', $values)));
        }
        if ($writer instanceof XlsxWriter) {
            $writer->getCurrentSheet()->setColumnWidth(20, ...range(1, count($headers)));
        }
        $writer->close();

        return response()->download($path, "{$filename}.{$format}")->deleteFileAfterSend();
    }
}
