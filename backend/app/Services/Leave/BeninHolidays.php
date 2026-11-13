<?php

namespace App\Services\Leave;

use Carbon\CarbonImmutable;

/**
 * Jours fériés du Bénin.
 *
 * Les fêtes à date fixe et les fêtes chrétiennes mobiles sont calculées. Les fêtes musulmanes
 * (Ramadan, Tabaski, Maouloud) dépendent de l'observation de la lune : leur date est fixée chaque
 * année par communiqué du gouvernement. Elles sont proposées ici à leur date prévisionnelle et
 * signalées comme telles, à corriger par les RH dans « Paramètres congés ».
 */
class BeninHolidays
{
    /** Dates prévisionnelles des fêtes musulmanes : [Ramadan, Tabaski, Maouloud]. */
    private const ISLAMIC = [
        2025 => ['03-31', '06-06', '09-05'],
        2026 => ['03-20', '05-27', '08-26'],
        2027 => ['03-10', '05-17', '08-15'],
        2028 => ['02-27', '05-05', '08-04'],
        2029 => ['02-15', '04-24', '07-24'],
        2030 => ['02-05', '04-14', '07-13'],
    ];

    /**
     * @return array<string, string> date (Y-m-d) => libellé
     */
    public static function forYear(int $year): array
    {
        $easter = self::easterSunday($year);

        $holidays = [
            "$year-01-01" => 'Jour de l’an',
            "$year-01-10" => 'Fête des religions endogènes (Vodoun)',
            $easter->addDay()->toDateString() => 'Lundi de Pâques',
            "$year-05-01" => 'Fête du Travail',
            $easter->addDays(39)->toDateString() => 'Ascension',
            $easter->addDays(50)->toDateString() => 'Lundi de Pentecôte',
            "$year-08-01" => 'Fête nationale (Indépendance)',
            "$year-08-15" => 'Assomption',
            "$year-11-01" => 'Toussaint',
            "$year-12-25" => 'Noël',
        ];

        if (isset(self::ISLAMIC[$year])) {
            [$ramadan, $tabaski, $maouloud] = self::ISLAMIC[$year];
            foreach ([$ramadan => 'Ramadan (Aïd el-Fitr)', $tabaski => 'Tabaski (Aïd el-Adha)', $maouloud => 'Maouloud'] as $day => $name) {
                // Une fête fixe le même jour garde son libellé
                $holidays["$year-$day"] ??= "$name — date prévisionnelle";
            }
        }
        ksort($holidays);

        return $holidays;
    }

    /** Algorithme de Meeus/Jones/Butcher (calendrier grégorien). */
    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}
