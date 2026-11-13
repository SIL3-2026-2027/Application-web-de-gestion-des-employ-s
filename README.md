# Gestion des employés

Application web de gestion RH : front-end **React** (`frontend/`) et API **Laravel** (`backend/`).

## Prérequis

- PHP 8.2+ et Composer
- Node.js 20+
- MySQL / MariaDB (XAMPP convient)

## Installation

```bash
# Base de données
mysql -u root -e "CREATE DATABASE gestion_employes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# Back-end
cd backend
composer install
cp .env.example .env        # puis renseigner DB_* et SANCTUM_STATEFUL_DOMAINS=localhost:5173
php artisan key:generate
php artisan migrate --seed  # crée les tables et les données de démonstration

# Front-end
cd ../frontend
npm install
```

## Tâches planifiées

- `contracts:apply-due` (00 h 30) : applique les avenants arrivés à leur date d'effet ;
- `notifications:contract-deadlines` (7 h) : prévient les RH et le manager des fins de contrat
  et de période d'essai à J-30 et J-7 ;
- `notifications:document-expiry` (7 h 05) : prévient les RH des pièces qui expirent à J-30 et J-7 ;
- `attendance:anomalies absences` (10 h 30, jours ouvrés) et `attendance:anomalies sorties` (21 h) :
  signalent aux managers les absences non justifiées et les départs non pointés.

Les fichiers déposés sont stockés hors du dossier public (`backend/storage/app/private`) et ne
sont accessibles qu'à travers l'API, après contrôle des droits. Penser à les inclure dans les
sauvegardes.

En production, ajouter au cron du serveur :

```
* * * * * cd /chemin/vers/backend && php artisan schedule:run >> /dev/null 2>&1
```

## Documents générés

Les informations de l'entreprise imprimées sur les contrats et certificats se règlent dans
`backend/.env` : `COMPANY_NAME`, `COMPANY_ADDRESS`, `COMPANY_POSTAL_CODE`, `COMPANY_CITY`,
`COMPANY_SIRET`, `COMPANY_REPRESENTATIVE`, `COMPANY_REPRESENTATIVE_TITLE`.

## Lancement en développement

Dans trois terminaux :

```bash
cd backend && php artisan serve          # API sur http://localhost:8000
cd backend && php artisan queue:work     # envoi des e-mails de notification
cd frontend && npm run dev               # Application sur http://localhost:5173
```

En développement, les e-mails ne partent pas : ils sont écrits dans `backend/storage/logs/laravel.log`
(`MAIL_MAILER=log`). En production, renseigner un vrai serveur SMTP (`MAIL_MAILER=smtp`, `MAIL_HOST`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`) et faire tourner `queue:work` en continu
(Supervisor ou service système).

Ouvrir http://localhost:5173. Vite redirige `/api` et `/sanctum` vers Laravel, l'authentification
se fait par cookie de session Sanctum (pas de jeton stocké dans le navigateur).

## Comptes de démonstration

Mot de passe : `password`

| Rôle | E-mail |
| --- | --- |
| Administrateur | admin@entreprise.test |
| Ressources humaines | rh@entreprise.test |
| Direction | direction@entreprise.test |
| Manager | manager@entreprise.test |
| Employé | employe@entreprise.test |

## Comptes utilisateurs

Personne ne peut créer son propre compte : c'est l'administration (administrateur ou RH) qui
inscrit chaque employé, depuis **Comptes utilisateurs** ou directement à la création du dossier
employé. Elle saisit l'identifiant (e-mail professionnel) et le mot de passe ; aucun e-mail n'est
envoyé. Par défaut, l'employé doit remplacer ce mot de passe à sa première connexion.

Le seul e-mail envoyé concerne le **mot de passe oublié** (lien valable 30 minutes).

- L'administrateur attribue tous les rôles ; les RH attribuent manager, employé et direction.
- Un compte désactivé est déconnecté immédiatement ; il reste toujours un administrateur actif.
- Mot de passe : 10 caractères minimum, majuscule, minuscule, chiffre et caractère spécial.

## Tests

Les tests Laravel utilisent une base MySQL dédiée (vidée à chaque test) :

```bash
mysql -u root -e "CREATE DATABASE gestion_employes_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
cd backend && php artisan test
cd frontend && npm run build && npm run lint
```

## Avancement (cahier des charges)

Lot 1 — socle : **terminé**

- [x] AUTH-01/03/05 Connexion, blocage après 5 échecs, déconnexion
- [x] Rôles et droits (super-admin, rh, manager, employe, direction)
- [x] EMP-01/02/03/05/06/10 Dossiers employés, recherche et filtres, historique, départ, structure
- [x] RAP-01/02 Tableau de bord
- [x] CTR-01 à 06 Contrats et avenants : CDI/CDD/stage…, avenants (salaire, horaires, poste,
      prolongation) appliqués à leur date d'effet, fin de contrat avec départ, alertes d'échéance,
      PDF du contrat, de l'avenant et du certificat de travail, dépôt de l'exemplaire signé
- [x] CNG-01 à 07 Congés et absences : types paramétrables, demande avec calcul des jours ouvrés
      (week-ends, jours fériés, demi-journées), validation manager puis RH, soldes et ajustements,
      calendrier d'équipe, déclaration d'absence par les RH, justificatifs
- [x] DOC-01 à 06 Documents : coffre-fort par employé (catégories et visibilité paramétrables,
      PDF/images/Word 10 Mo), attestations de travail et de salaire générées, demandes en ligne,
      documents de l'entreprise avec accusé de lecture, alertes d'expiration des pièces
- [x] NOT-01/02/03/05 Notifications : cloche dans l'application, e-mails (demande à valider,
      décision, absence saisie par les RH, échéances de contrat), préférences d'e-mail par catégorie
- [x] EMP-07 Import des employés (Excel/CSV, modèle fourni, contrôle ligne par ligne, contrats et
      managers, création des départements/postes/sites manquants) ; export Excel, CSV et PDF
      de la liste filtrée
- [x] ADM-01 Comptes utilisateurs créés par l'administration (mot de passe saisi à l'inscription),
      rôles, désactivation ; AUTH-02 mot de passe oublié ; AUTH-03 politique de mot de passe ;
      AUTH-06 changement de mot de passe (obligatoire à la première connexion)

Lot 2 — temps et paie :

- [x] PRS-01 à 06 Présences : horaires types, pointage (avec position GPS facultative), feuille
      de temps mensuelle (prévu, travaillé, retards, absences, congés, jours fériés), heures
      supplémentaires et corrections validées par le manager, saisie RH, alertes d'anomalies
- [x] PAI-01 à 05 Paie : rubriques avec code d'export paramétrable, éléments récurrents,
      préparation du mois (heures supplémentaires validées réparties 25 % / 50 % par semaine,
      congés, absences non justifiées), saisies manuelles prioritaires sur le calcul, clôture et
      réouverture tracée, export CSV/Excel (par élément ou par salarié), dépôt des bulletins PDF
      classés par matricule et envoyés aux salariés
- [ ] RAP-04 Indicateurs RH
