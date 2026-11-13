<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;

class AttendanceSeeder extends Seeder
{
    public function run(): void
    {
        $weekdays = fn (string $start, string $end, int $break) => collect(range(1, 5))->mapWithKeys(fn ($d) => [$d => ['start' => $start, 'end' => $end, 'break' => $break]])->all();

        WorkSchedule::create(['name' => 'Standard 40 h (8 h – 17 h)', 'days' => $weekdays('08:00', '17:00', 60), 'late_tolerance' => 10, 'is_default' => true]);
        WorkSchedule::create(['name' => 'Journée coupée (8 h – 12 h 30 / 15 h – 18 h 30)', 'days' => $weekdays('08:00', '18:30', 150), 'late_tolerance' => 10]);
        WorkSchedule::create([
            'name' => 'Temps partiel 4/5 (lundi – jeudi)',
            'days' => collect(range(1, 4))->mapWithKeys(fn ($d) => [$d => ['start' => '08:00', 'end' => '17:00', 'break' => 60]])->all(),
            'late_tolerance' => 10,
        ]);

        // Trois semaines de pointages pour l'équipe informatique (démonstration de la feuille de temps)
        $manager = User::where('email', 'manager@entreprise.test')->first()?->employee;
        if (! $manager) {
            return;
        }
        $team = Employee::where('manager_id', $manager->id)->orWhere('id', $manager->id)->get();
        $holidays = PublicHoliday::pluck('date')->map(fn ($d) => $d->toDateString())->flip();
        $today = CarbonImmutable::today();

        foreach ($team as $i => $employee) {
            foreach (CarbonPeriod::create($today->subWeeks(3)->startOfWeek(), $today) as $d) {
                $date = CarbonImmutable::instance($d);
                if ($date->isWeekend() || $holidays->has($date->toDateString()) || $date->lt($employee->hire_date)) {
                    continue;
                }
                if (($i + $date->day) % 17 === 0) {
                    continue; // absence non justifiée de démonstration
                }

                $arrival = $date->setTime(7, 50)->addMinutes(($i * 7 + $date->day * 3) % 35); // 7 h 50 – 8 h 25
                if ($date->isSameDay($today)) {
                    if ($arrival->lt(now())) {
                        Attendance::create(['employee_id' => $employee->id, 'date' => $date, 'clock_in' => $arrival, 'source' => 'application']);
                    }
                    continue;
                }

                $lunchOut = $date->setTime(12, 30)->addMinutes($i % 3 * 5);
                $lunchIn = $lunchOut->addMinutes(55 + $i % 2 * 10);
                $departure = $date->setTime(17, 0)->addMinutes(($i * 11 + $date->day * 5) % 70); // 17 h – 18 h 10

                Attendance::create(['employee_id' => $employee->id, 'date' => $date, 'clock_in' => $arrival, 'clock_out' => $lunchOut]);
                // Un oubli de pointage de retour de déjeuner, pour la démonstration
                if ($i === 1 && $date->isSameDay($today->subWeekday())) {
                    continue;
                }
                Attendance::create(['employee_id' => $employee->id, 'date' => $date, 'clock_in' => $lunchIn, 'clock_out' => $departure]);
            }
        }

        $member = $team->firstWhere('id', '!=', $manager->id);
        OvertimeRequest::create([
            'employee_id' => $member->id,
            'date' => $today->subWeekdays(2),
            'minutes' => 90,
            'reason' => 'Mise en production du nouveau site',
        ]);
        AttendanceCorrection::create([
            'employee_id' => $team[1]->id,
            'date' => $today->subWeekday(),
            'segments' => [['in' => '08:05', 'out' => '12:30'], ['in' => '13:30', 'out' => '17:30']],
            'reason' => 'Oubli de pointage au retour du déjeuner',
        ]);
    }
}
