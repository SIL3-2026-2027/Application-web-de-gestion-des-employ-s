<?php

namespace App\Services\Leave;

use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/** Calcul du nombre de jours ouvrés d'une absence (CNG-02). */
class WorkingDays
{
    /**
     * Jours ouvrés (lundi–vendredi, hors jours fériés) entre deux dates incluses.
     * Une demi-journée au début (départ l'après-midi) ou à la fin (retour l'après-midi) retire 0,5 jour.
     */
    public function count(string $start, string $end, bool $startHalfDay = false, bool $endHalfDay = false): float
    {
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);

        if ($to->lt($from)) {
            return 0;
        }

        $holidays = PublicHoliday::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => $d->toDateString())
            ->flip();

        $isWorking = fn (CarbonImmutable $d) => ! $d->isWeekend() && ! $holidays->has($d->toDateString());

        $days = 0;
        foreach (CarbonPeriod::create($from, $to) as $day) {
            if ($isWorking(CarbonImmutable::instance($day))) {
                $days++;
            }
        }

        if ($startHalfDay && $isWorking($from)) {
            $days -= 0.5;
        }
        if ($endHalfDay && $isWorking($to)) {
            $days -= 0.5;
        }

        return max(0, $days);
    }
}
