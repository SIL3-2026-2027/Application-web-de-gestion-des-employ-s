<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\PayrollRubric;
use App\Models\RecurringPayItem;
use App\Models\User;
use Illuminate\Database\Seeder;

class PayrollSeeder extends Seeder
{
    public function run(): void
    {
        // Codes d'export d'exemple : à remplacer par ceux du logiciel de paie de l'entreprise
        $rubrics = collect([
            ['code' => 'PRIME_EXC', 'label' => 'Prime exceptionnelle', 'kind' => 'gain', 'unit' => 'montant', 'export_code' => 'P100'],
            ['code' => 'PRIME_FIXE', 'label' => 'Prime mensuelle fixe', 'kind' => 'gain', 'unit' => 'montant', 'export_code' => 'P110'],
            ['code' => 'IND_TRANSPORT', 'label' => 'Prise en charge transport', 'kind' => 'gain', 'unit' => 'montant', 'export_code' => 'P200'],
            ['code' => 'AVN_REPAS', 'label' => 'Avantage en nature repas', 'kind' => 'gain', 'unit' => 'montant', 'export_code' => 'P300'],
            ['code' => 'ACOMPTE', 'label' => 'Acompte sur salaire', 'kind' => 'retenue', 'unit' => 'montant', 'export_code' => 'R100'],
            ['code' => PayrollRubric::OVERTIME_25, 'label' => 'Heures supplémentaires à 25 %', 'kind' => 'gain', 'unit' => 'heures', 'export_code' => 'H125'],
            ['code' => PayrollRubric::OVERTIME_50, 'label' => 'Heures supplémentaires à 50 %', 'kind' => 'gain', 'unit' => 'heures', 'export_code' => 'H150'],
            ['code' => PayrollRubric::UNJUSTIFIED_ABSENCE, 'label' => 'Absence non justifiée', 'kind' => 'retenue', 'unit' => 'jours', 'export_code' => 'A900'],
            // Même code que le type de congé : repris automatiquement depuis les congés approuvés
            ['code' => 'CP', 'label' => 'Congés payés pris', 'kind' => 'information', 'unit' => 'jours', 'export_code' => 'C100'],
            ['code' => 'RTT', 'label' => 'RTT pris', 'kind' => 'information', 'unit' => 'jours', 'export_code' => 'C200'],
            ['code' => 'MAL', 'label' => 'Absence maladie', 'kind' => 'information', 'unit' => 'jours', 'export_code' => 'A100'],
            ['code' => 'CSS', 'label' => 'Congé sans solde', 'kind' => 'retenue', 'unit' => 'jours', 'export_code' => 'A200'],
            ['code' => 'MAT', 'label' => 'Congé maternité / paternité', 'kind' => 'information', 'unit' => 'jours', 'export_code' => 'A300'],
            ['code' => 'EVF', 'label' => 'Événement familial', 'kind' => 'information', 'unit' => 'jours', 'export_code' => 'A400'],
        ])->mapWithKeys(fn ($r) => [$r['code'] => PayrollRubric::create($r)]);

        // Éléments récurrents de démonstration
        $month = now()->subMonths(3)->format('Y-m');
        foreach (Employee::where('status', '!=', 'sorti')->orderBy('id')->limit(6)->get() as $i => $e) {
            RecurringPayItem::create(['employee_id' => $e->id, 'payroll_rubric_id' => $rubrics['IND_TRANSPORT']->id, 'amount' => 43.20, 'start_month' => $month, 'comment' => 'Pass Navigo (50 %)']);
            if ($i === 0) {
                RecurringPayItem::create(['employee_id' => $e->id, 'payroll_rubric_id' => $rubrics['PRIME_FIXE']->id, 'amount' => 300, 'start_month' => $month, 'comment' => 'Prime de fonction']);
            }
        }

        // Heures supplémentaires déjà validées le mois dernier
        $manager = User::where('email', 'manager@entreprise.test')->first();
        $member = $manager?->employee?->subordinates()->first();
        if ($member) {
            $week = now()->subMonth()->startOfMonth()->next('Monday');
            foreach ([0 => 240, 1 => 180, 3 => 150] as $offset => $minutes) {
                OvertimeRequest::create([
                    'employee_id' => $member->id,
                    'date' => $week->copy()->addDays($offset),
                    'minutes' => $minutes,
                    'reason' => 'Clôture du projet client',
                    'status' => 'approuve',
                    'decided_by' => $manager->id,
                    'decided_at' => now()->subMonth(),
                ]);
            }
        }
    }
}
