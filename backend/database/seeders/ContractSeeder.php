<?php

namespace Database\Seeders;

use App\Models\Contract;
use App\Models\Employee;
use Illuminate\Database\Seeder;

class ContractSeeder extends Seeder
{
    /** Salaire brut mensuel indicatif par poste, en FCFA. */
    private const SALARIES = [
        'Directeur général' => 1500000,
        'Responsable RH' => 650000,
        'Chef de projet' => 600000,
        'Développeur' => 400000,
        'Commercial' => 250000,
        'Comptable' => 300000,
        'Assistant administratif' => 150000,
    ];

    public function run(): void
    {
        $employees = Employee::with('position')->orderBy('id')->get();

        foreach ($employees as $i => $e) {
            $salary = self::SALARIES[$e->position?->title] ?? 200000;
            $onProbation = $e->status === 'periode_essai';

            // Un employé sur cinq en CDD, dont certains arrivent à échéance dans le mois (alertes CTR-04)
            $cdd = $i % 5 === 4;
            $start = match (true) {
                $onProbation => now()->subMonths(2)->startOfMonth(),
                $cdd => now()->subMonths(6)->startOfMonth(),
                default => $e->hire_date,
            };

            $contract = Contract::create([
                'employee_id' => $e->id,
                'kind' => 'contrat',
                'type' => $cdd ? 'CDD' : 'CDI',
                'start_date' => $start,
                'end_date' => $cdd ? now()->addDays(10 + $i)->toDateString() : null,
                'trial_end_date' => $onProbation ? now()->addDays(12)->toDateString() : $start->copy()->addMonths($cdd ? 1 : 3)->toDateString(),
                'weekly_hours' => 40,
                'base_salary' => $cdd ? $salary * 0.95 : $salary,
                'position_id' => $e->position_id,
                'department_id' => $e->department_id,
                'collective_agreement' => 'Convention collective générale du travail du 30 décembre 2005',
                'description' => $cdd ? 'Accroissement temporaire d’activité' : null,
                'applied_at' => now(),
            ]);

            if ($onProbation || $cdd) {
                $e->update(['hire_date' => $start]);
            }

            // Une augmentation pour les plus anciens
            if (! $cdd && $e->hire_date->lt(now()->subYears(3))) {
                Contract::create([
                    'employee_id' => $e->id,
                    'parent_id' => $contract->id,
                    'kind' => 'avenant',
                    'type' => $contract->type,
                    'start_date' => now()->subYear()->startOfYear(),
                    'base_salary' => round($salary * 1.04, -2),
                    'description' => 'Augmentation individuelle annuelle',
                    'applied_at' => now(),
                ]);
            }
        }
    }
}
