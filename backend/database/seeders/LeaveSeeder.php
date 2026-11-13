<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Leave\BeninHolidays;
use App\Services\Leave\WorkingDays;
use Illuminate\Database\Seeder;

class LeaveSeeder extends Seeder
{
    public function run(): void
    {
        $types = collect([
            // Code du travail : 2 jours ouvrables de congé par mois de service, soit 24 jours par an
            ['name' => 'Congés payés', 'code' => 'CP', 'color' => '#2e75b6', 'is_deductible' => true, 'monthly_accrual' => 2, 'annual_allowance' => 24],
            // Convention collective : permissions pour événements familiaux, 10 jours par an au plus
            ['name' => 'Permission exceptionnelle', 'code' => 'PERM', 'color' => '#7c3aed', 'is_deductible' => true, 'annual_allowance' => 10, 'requires_attachment' => true],
            ['name' => 'Maladie', 'code' => 'MAL', 'color' => '#dc2626', 'is_deductible' => false, 'requires_attachment' => true, 'employee_can_request' => false],
            ['name' => 'Congé sans solde', 'code' => 'CSS', 'color' => '#64748b', 'is_deductible' => false, 'requires_hr_approval' => true],
            ['name' => 'Télétravail', 'code' => 'TT', 'color' => '#059669', 'is_deductible' => false],
            // 14 semaines, dont 6 avant l'accouchement ; indemnisé par la CNSS
            ['name' => 'Congé de maternité', 'code' => 'MAT', 'color' => '#ea580c', 'is_deductible' => false, 'requires_attachment' => true, 'employee_can_request' => false],
            ['name' => 'Congé de paternité', 'code' => 'PAT', 'color' => '#db2777', 'is_deductible' => false, 'requires_attachment' => true],
        ])->mapWithKeys(fn ($t) => [$t['code'] => LeaveType::create($t)]);

        foreach ([now()->year - 1, now()->year, now()->year + 1] as $year) {
            foreach (BeninHolidays::forYear($year) as $date => $name) {
                PublicHoliday::firstOrCreate(['date' => $date], ['name' => $name]);
            }
        }

        // Quelques demandes de démonstration pour l'équipe du manager
        $manager = User::where('email', 'manager@entreprise.test')->first()?->employee;
        if (! $manager) {
            return;
        }

        $workingDays = app(WorkingDays::class);
        $team = Employee::where('manager_id', $manager->id)->get();
        $monday = now()->startOfWeek();

        $make = function (Employee $e, string $code, int $offset, int $length, string $status) use ($types, $workingDays, $monday) {
            $start = $monday->copy()->addDays($offset);
            $end = $start->copy()->addDays($length - 1);
            LeaveRequest::create([
                'employee_id' => $e->id,
                'leave_type_id' => $types[$code]->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => $workingDays->count($start->toDateString(), $end->toDateString()),
                'status' => $status,
                'created_by' => $e->user_id,
                'reason' => $status === LeaveRequest::PENDING ? ($code === 'PERM' ? 'Mariage d’un proche' : 'Congé annuel en famille') : null,
            ]);
        };

        $make($team[0], 'CP', 7, 5, LeaveRequest::PENDING);
        $make($team[1], 'PERM', 2, 1, LeaveRequest::PENDING);
        $make($team[2], 'CP', 14, 12, LeaveRequest::APPROVED);
        $make($team[3], 'TT', 1, 1, LeaveRequest::APPROVED);
        $make($team[4], 'CP', -21, 5, LeaveRequest::APPROVED);
    }
}
