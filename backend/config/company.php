<?php

/*
 * Informations de l'entreprise reprises sur les documents générés (contrats, certificats).
 * À terme, elles seront modifiables depuis l'administration (ADM-02).
 */
return [
    'name' => env('COMPANY_NAME', 'Mon Entreprise SAS'),
    'address' => env('COMPANY_ADDRESS', '1 rue de la Paix'),
    'postal_code' => env('COMPANY_POSTAL_CODE', '75002'),
    'city' => env('COMPANY_CITY', 'Paris'),
    'siret' => env('COMPANY_SIRET', '000 000 000 00000'),
    'representative' => env('COMPANY_REPRESENTATIVE', 'Le représentant légal'),
    'representative_title' => env('COMPANY_REPRESENTATIVE_TITLE', 'Directeur général'),
];
