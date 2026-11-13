<?php

/*
 * Paramètres RH, préréglés pour le Bénin (Code du travail, loi n° 98-004 du 27 janvier 1998,
 * et convention collective générale du travail du 30 décembre 2005).
 */
return [
    // Monnaie : franc CFA (UEMOA), sans décimales
    'currency' => env('HR_CURRENCY', 'FCFA'),

    // Durée légale hebdomadaire du travail
    'weekly_hours' => (float) env('HR_WEEKLY_HOURS', 40),

    // Heures supplémentaires de jour : 12 % de la 41e à la 48e heure, 35 % au-delà
    'overtime' => [
        'first_tier_hours' => (int) env('HR_OVERTIME_FIRST_TIER_HOURS', 8),
        'first_tier_rate' => 12,
        'second_tier_rate' => 35,
    ],

    // Types de congé comptés dans le taux d'absentéisme (RAP-04), par code
    // (par défaut : maladie et congé sans solde ; les congés payés, permissions, maternité
    // et télétravail sont des absences prévues et ne comptent pas)
    'absenteeism_leave_codes' => explode(',', env('HR_ABSENTEEISM_LEAVE_CODES', 'MAL,CSS')),
];
