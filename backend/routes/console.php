<?php

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Notifications\ContractDeadline;
use App\Notifications\SimpleNotification;
use App\Services\AttendanceService;
use App\Services\ContractService;
use App\Services\EvaluationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Contrats et avenants : reporte poste et département sur le dossier à la date d'effet (CTR-03)
Artisan::command('contracts:apply-due', function (ContractService $service) {
    $this->info($service->applyAllDue().' contrat(s) ou avenant(s) appliqué(s).');
})->purpose("Applique les contrats et avenants arrivés à leur date d'effet");

// NOT-03 / CTR-04 : rappel des fins de contrat et de période d'essai à J-30 et J-7 (RH + manager)
Artisan::command('notifications:contract-deadlines', function (ContractService $service) {
    $sent = 0;
    $hr = User::hrRecipients();

    foreach ($service->upcomingDeadlines(Employee::query(), 30) as $d) {
        if (! in_array($d['days_left'], [30, 7], true)) {
            continue;
        }

        $recipients = $hr->collect();
        $manager = $d['contract']->employee->manager?->user;
        if ($manager?->is_active) {
            $recipients->push($manager);
        }

        Notification::send($recipients->unique('id'), new ContractDeadline($d['contract'], $d['kind'], $d['date'], $d['days_left']));
        $sent++;
    }

    $this->info("{$sent} échéance(s) notifiée(s).");
})->purpose("Prévient les RH et le manager des fins de contrat et de période d'essai à J-30 et J-7");

// DOC-06 / EMP-11 : pièces qui expirent à J-30 et J-7 (titre de séjour, visite médicale…), signalées aux RH
Artisan::command('notifications:document-expiry', function () {
    $sent = 0;
    $hr = User::hrRecipients();

    $documents = EmployeeDocument::query()
        ->whereIn('expires_at', [now()->addDays(30)->toDateString(), now()->addDays(7)->toDateString()])
        ->whereHas('employee', fn ($q) => $q->where('status', '!=', 'sorti'))
        ->with(['employee', 'category'])
        ->get();

    foreach ($documents as $d) {
        $days = (int) now()->startOfDay()->diffInDays($d->expires_at);
        Notification::send($hr, new SimpleNotification(
            'documents',
            "{$d->category->name} expire dans {$days} jours — {$d->employee->full_name}",
            "« {$d->title} » de {$d->employee->full_name} expire le {$d->expires_at->format('d/m/Y')}. Demandez le document renouvelé.",
            "/employes/{$d->employee_id}",
        ));
        $sent++;
    }

    $this->info("{$sent} document(s) signalé(s).");
})->purpose('Prévient les RH des pièces qui expirent à J-30 et J-7');

// PRS-06 : anomalies de présence signalées au manager (ou aux RH sans manager)
//   --absences : employés attendus ce matin, sans pointage ni congé (après l'heure d'arrivée + tolérance)
//   --sorties  : départs non pointés de la journée (le soir)
Artisan::command('attendance:anomalies {type : absences ou sorties}', function (AttendanceService $attendance, string $type) {
    if (! in_array($type, ['absences', 'sorties'], true)) {
        $this->error('Type attendu : absences ou sorties');

        return 1;
    }

    $byRecipient = [];
    $employees = Employee::where('status', '!=', 'sorti')->with(['manager.user', 'user', 'workSchedule'])->get();

    foreach ($employees as $employee) {
        $day = $attendance->todayStatus($employee);
        $problem = $type === 'absences'
            ? ($day['status'] === 'non_arrive' ? "{$employee->full_name} (attendu à {$day['planned_start']})" : null)
            : (collect($day['segments'])->contains(fn ($s) => $s['out'] === null) ? $employee->full_name : null);
        if (! $problem) {
            continue;
        }

        $manager = $employee->manager?->user;
        $recipients = $manager?->is_active ? collect([$manager]) : User::hrRecipients();
        foreach ($recipients as $user) {
            $byRecipient[$user->id]['user'] = $user;
            $byRecipient[$user->id]['names'][] = $problem;
        }

        // Le soir, l'employé qui a oublié de pointer son départ est aussi prévenu
        if ($type === 'sorties' && $employee->user?->is_active) {
            $employee->user->notify(new SimpleNotification('attendance', 'Départ non pointé',
                'Vous n’avez pas pointé votre départ aujourd’hui. Faites une demande de correction depuis « Pointage ».', '/pointage'));
        }
    }

    foreach ($byRecipient as ['user' => $user, 'names' => $names]) {
        $user->notify(new SimpleNotification(
            'attendance',
            $type === 'absences' ? count($names).' absence(s) non justifiée(s) ce matin' : count($names).' départ(s) non pointé(s)',
            implode(', ', $names).'.',
            '/presences',
        ));
    }

    $this->info(count($byRecipient).' destinataire(s) prévenu(s).');
})->purpose('Signale les absences non justifiées et les départs non pointés');

// EVA-07 : relance des entretiens qui approchent de leur échéance ou sont en retard
Artisan::command('evaluations:reminders', function (EvaluationService $evaluations) {
    $this->info($evaluations->remind().' entretien(s) relancé(s).');
})->purpose('Relance employés et managers dont l’entretien approche de son échéance');

Schedule::command('evaluations:reminders')->weekdays()->at('08:00');
Schedule::command('attendance:anomalies absences')->weekdays()->at('10:30');
Schedule::command('attendance:anomalies sorties')->dailyAt('21:00');
Schedule::command('contracts:apply-due')->dailyAt('00:30');
Schedule::command('notifications:document-expiry')->dailyAt('07:05');
Schedule::command('notifications:contract-deadlines')->dailyAt('07:00');
