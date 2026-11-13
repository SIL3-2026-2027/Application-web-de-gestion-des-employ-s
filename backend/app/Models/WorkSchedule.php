<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkSchedule extends Model
{
    public const DAY_NAMES = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
        7 => 'Dimanche',
    ];

    private const DAY_KEYS = [
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
        7 => 'sunday',
    ];

    protected $fillable = [
        'name',
        'days',
        'late_tolerance',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'late_tolerance' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public static function default(): ?self
    {
        return static::where('is_default', true)->first();
    }

    /**
     * Retourne les horaires prévus pour un jour donné.
     */
    public function forDay(CarbonInterface $date): ?array
    {
        $dayKey = self::DAY_KEYS[$date->dayOfWeekIso] ?? null;

        if ($dayKey === null) {
            return null;
        }

        $day = $this->days[$dayKey] ?? null;

        return is_array($day) ? array_values($day) : null;
    }

    /**
     * Calcule les minutes de travail effectif d'un jour.
     *
     * Format attendu :
     * [début matin, fin matin, début après-midi, fin après-midi]
     */
    private static function minutesForDay(array $day): int
    {
        if (count($day) !== 4) {
            return 0;
        }

        foreach ($day as $time) {
            if (! is_string($time) || ! preg_match(
                '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
                $time
            )) {
                return 0;
            }
        }

        $morning = self::toMinutes($day[1])
            - self::toMinutes($day[0]);

        $afternoon = self::toMinutes($day[3])
            - self::toMinutes($day[2]);

        return max(0, $morning) + max(0, $afternoon);
    }

    /**
     * Minutes de travail prévues un jour donné.
     */
    public function plannedMinutes(CarbonInterface $date): int
    {
        $day = $this->forDay($date);

        if ($day === null) {
            return 0;
        }

        return self::minutesForDay($day);
    }

    /**
     * Total des minutes de travail prévues dans la semaine.
     */
    public function weeklyMinutes(): int
    {
        $total = 0;

        foreach ($this->days ?? [] as $day) {
            if (is_array($day)) {
                $total += self::minutesForDay(array_values($day));
            }
        }

        return $total;
    }

    public static function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }
}
