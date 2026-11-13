<?php

/*
 * Informations de l'entreprise reprises sur les documents générés (contrats, certificats, attestations).
 * Préréglées pour une entreprise béninoise : IFU, RCCM et numéro d'employeur CNSS.
 * À terme, elles seront modifiables depuis l'administration (ADM-02).
 */
return [
    'name' => env('COMPANY_NAME', 'Mon Entreprise SARL'),
    'address' => env('COMPANY_ADDRESS', 'Lot 1234, quartier Cadjèhoun'),
    'postal_box' => env('COMPANY_POSTAL_BOX', '01 BP 1234'),
    'city' => env('COMPANY_CITY', 'Cotonou'),
    'country' => env('COMPANY_COUNTRY', 'Bénin'),
    'ifu' => env('COMPANY_IFU', '0000000000000'),            // identifiant fiscal unique (13 chiffres)
    'rccm' => env('COMPANY_RCCM', 'RB/COT/00 B 0000'),       // registre du commerce et du crédit mobilier
    'cnss' => env('COMPANY_CNSS', '00000000'),               // numéro d'employeur à la CNSS
    'collective_agreement' => env('COMPANY_COLLECTIVE_AGREEMENT', 'Convention collective générale du travail du 30 décembre 2005'),
    'representative' => env('COMPANY_REPRESENTATIVE', 'Le représentant légal'),
    'representative_title' => env('COMPANY_REPRESENTATIVE_TITLE', 'Directeur général'),
];
