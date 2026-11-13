<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * EMP-07 : import initial des employés depuis Excel (.xlsx) ou CSV, avec contrôle ligne par ligne.
 * L'aperçu ne modifie rien ; l'import enregistre tout en une transaction.
 */
class EmployeeImportService
{
    public const MAX_ROWS = 2000;

    /** Colonnes du modèle : clé interne => en-tête affiché (« * » = obligatoire). */
    public const COLUMNS = [
        'matricule' => 'Matricule',
        'civilite' => 'Civilité (M. / Mme)',
        'nom' => 'Nom *',
        'prenom' => 'Prénom *',
        'date_naissance' => 'Date de naissance',
        'lieu_naissance' => 'Lieu de naissance',
        'nationalite' => 'Nationalité',
        'email_pro' => 'E-mail professionnel',
        'email_perso' => 'E-mail personnel',
        'telephone' => 'Téléphone',
        'adresse' => 'Adresse',
        'code_postal' => 'Boîte postale',
        'ville' => 'Ville',
        'departement' => 'Département (code ou nom)',
        'poste' => 'Poste',
        'site' => 'Site',
        'manager' => 'Matricule du manager',
        'date_embauche' => "Date d'embauche *",
        'statut' => 'Statut (actif / periode_essai)',
        'type_contrat' => 'Type de contrat (CDI, CDD, stage, alternance, interim, freelance)',
        'date_fin_contrat' => 'Fin du contrat',
        'heures_hebdo' => 'Heures par semaine',
        'salaire' => 'Salaire brut mensuel (FCFA)',
    ];

    /** Références à créer pendant l'import (option « créer les éléments manquants »). */
    private array $toCreate = ['departement' => [], 'poste' => [], 'site' => []];

    /**
     * Lit et contrôle le fichier sans rien enregistrer.
     *
     * @return array{rows: list<array{line: int, data: array, errors: list<string>, warnings: list<string>}>, valid: int, invalid: int, to_create: array}
     */
    public function preview(string $path, string $extension, bool $createMissing): array
    {
        $raw = $this->read($path, $extension);
        $this->toCreate = ['departement' => [], 'poste' => [], 'site' => []];

        $refs = [
            'departement' => Department::all(['id', 'code', 'name']),
            'poste' => Position::all(['id', 'title']),
            'site' => Site::all(['id', 'name']),
        ];
        $existingMatricules = Employee::withTrashed()->pluck('id', 'matricule')->mapWithKeys(fn ($id, $m) => [Str::upper($m) => $id]);
        $existingEmails = Employee::whereNotNull('work_email')->pluck('work_email')->map(fn ($e) => Str::lower($e))->flip();
        $fileMatricules = collect($raw)->pluck('data.matricule')->filter()->map(fn ($m) => Str::upper($m))->countBy();
        $fileEmails = collect($raw)->pluck('data.email_pro')->filter()->map(fn ($e) => Str::lower($e))->countBy();

        $rows = [];
        foreach ($raw as $r) {
            $rows[] = $this->checkRow($r['line'], $r['data'], $refs, $createMissing, $existingMatricules, $existingEmails, $fileMatricules, $fileEmails);
        }

        $invalid = collect($rows)->filter(fn ($r) => $r['errors'])->count();

        return [
            'rows' => $rows,
            'valid' => count($rows) - $invalid,
            'invalid' => $invalid,
            'to_create' => array_map(fn ($names) => array_values(array_unique($names)), $this->toCreate),
        ];
    }

    /**
     * Importe le fichier. Par défaut tout ou rien ; avec $onlyValid, les lignes en erreur sont ignorées.
     *
     * @return array{created: int, skipped: int, contracts: int}
     */
    public function import(User $actor, string $path, string $extension, bool $createMissing, bool $onlyValid): array
    {
        $preview = $this->preview($path, $extension, $createMissing);

        if ($preview['invalid'] > 0 && ! $onlyValid) {
            throw ValidationException::withMessages(['file' => "{$preview['invalid']} ligne(s) en erreur : corrigez le fichier ou importez seulement les lignes valides."]);
        }

        $rows = array_values(array_filter($preview['rows'], fn ($r) => ! $r['errors']));
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'Aucune ligne valide à importer.']);
        }

        $contracts = 0;
        DB::transaction(function () use ($actor, $rows, $preview, &$contracts) {
            // 1. Départements, postes et sites manquants
            foreach ($preview['to_create']['departement'] as $name) {
                Department::firstOrCreate(['name' => $name], ['code' => $this->uniqueDepartmentCode($name)]);
            }
            foreach ($preview['to_create']['poste'] as $title) {
                Position::firstOrCreate(['title' => $title]);
            }
            foreach ($preview['to_create']['site'] as $name) {
                Site::firstOrCreate(['name' => $name]);
            }

            $departments = Department::all();
            $positions = Position::all();
            $sites = Site::all();

            // 2. Employés (et contrat si renseigné)
            $byMatricule = [];
            $byLine = [];
            foreach ($rows as $row) {
                $d = $row['data'];
                $employee = Employee::create(array_filter([
                    'matricule' => $d['matricule'] ? Str::upper($d['matricule']) : null,
                    'civility' => $d['civilite'],
                    'last_name' => $d['nom'],
                    'first_name' => $d['prenom'],
                    'birth_date' => $d['date_naissance'],
                    'birth_place' => $d['lieu_naissance'],
                    'nationality' => $d['nationalite'],
                    'work_email' => $d['email_pro'] ? Str::lower($d['email_pro']) : null,
                    'personal_email' => $d['email_perso'],
                    'phone' => $d['telephone'],
                    'address' => $d['adresse'],
                    'postal_code' => $d['code_postal'],
                    'city' => $d['ville'],
                    'department_id' => $this->find($departments, 'departement', $d['departement'])?->id,
                    'position_id' => $this->find($positions, 'poste', $d['poste'])?->id,
                    'site_id' => $this->find($sites, 'site', $d['site'])?->id,
                    'hire_date' => $d['date_embauche'],
                    'status' => $d['statut'],
                ], fn ($v) => $v !== null && $v !== ''));
                $byMatricule[Str::upper($employee->matricule)] = $employee;
                $byLine[$row['line']] = $employee;

                if ($d['type_contrat']) {
                    Contract::create([
                        'employee_id' => $employee->id,
                        'type' => $d['type_contrat'],
                        'start_date' => $d['date_embauche'],
                        'end_date' => $d['date_fin_contrat'],
                        'weekly_hours' => $d['heures_hebdo'] ?? config('hr.weekly_hours'),
                        'base_salary' => $d['salaire'],
                        'position_id' => $employee->position_id,
                        'department_id' => $employee->department_id,
                        'applied_at' => now(),
                        'created_by' => $actor->id,
                    ]);
                    $contracts++;
                }
            }

            // 3. Managers (peuvent être dans le même fichier)
            foreach ($rows as $row) {
                if ($manager = $row['data']['manager']) {
                    $managerId = ($byMatricule[Str::upper($manager)] ?? Employee::where('matricule', $manager)->first())?->id;
                    $byLine[$row['line']]->update(['manager_id' => $managerId]);
                }
            }
        });

        activity('import')->causedBy($actor)->withProperties(['created' => count($rows), 'skipped' => $preview['invalid']])->log('Import des employés');

        return ['created' => count($rows), 'skipped' => $preview['invalid'], 'contracts' => $contracts];
    }

    // ---------- Lecture ----------

    /** @return list<array{line: int, data: array<string, mixed>}> */
    private function read(string $path, string $extension): array
    {
        $reader = $extension === 'xlsx' ? new XlsxReader : new CsvReader($this->csvOptions($path));
        $reader->open($path);

        $rows = [];
        $map = null;
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = $row->toArray();
                    if ($map === null) {
                        $map = $this->mapHeaders($cells);
                        continue;
                    }
                    if (collect($cells)->every(fn ($c) => $c === null || trim((string) ($c instanceof DateTimeInterface ? 'x' : $c)) === '')) {
                        continue; // ligne vide
                    }
                    if (count($rows) >= self::MAX_ROWS) {
                        throw ValidationException::withMessages(['file' => 'Le fichier dépasse '.self::MAX_ROWS.' lignes : découpez-le en plusieurs fichiers.']);
                    }
                    $data = [];
                    foreach (self::COLUMNS as $key => $label) {
                        $value = isset($map[$key]) ? ($cells[$map[$key]] ?? null) : null;
                        $data[$key] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
                    }
                    $rows[] = ['line' => $line, 'data' => $data];
                }
                break; // première feuille seulement
            }
        } finally {
            $reader->close();
        }

        if ($map === null) {
            throw ValidationException::withMessages(['file' => 'Le fichier est vide.']);
        }

        return $rows;
    }

    /** Associe chaque colonne connue à sa position, quel que soit l'ordre ou la casse des en-têtes. */
    private function mapHeaders(array $headers): array
    {
        $aliases = [];
        foreach (self::COLUMNS as $key => $label) {
            $aliases[$this->normalize($label)] = $key;
            $aliases[$this->normalize($key)] = $key;
        }

        $map = [];
        foreach ($headers as $index => $header) {
            $key = $aliases[$this->normalize((string) $header)] ?? null;
            if ($key && ! isset($map[$key])) {
                $map[$key] = $index;
            }
        }

        $missing = array_diff(['nom', 'prenom', 'date_embauche'], array_keys($map));
        if ($missing) {
            throw ValidationException::withMessages(['file' => 'Colonnes obligatoires absentes : '.implode(', ', array_map(fn ($k) => rtrim(self::COLUMNS[$k], ' *'), $missing)).'. Utilisez le modèle fourni.']);
        }

        return $map;
    }

    private function normalize(string $header): string
    {
        return Str::slug(preg_replace('/\(.*?\)|\*/u', '', $header), '_');
    }

    /** Fichiers CSV d'Excel en français : séparateur « ; » et encodage Windows-1252 fréquents. */
    private function csvOptions(string $path): CsvOptions
    {
        $options = new CsvOptions;
        $sample = (string) file_get_contents($path, length: 4096);
        $firstLine = strtok($sample, "\n") ?: '';
        $options->FIELD_DELIMITER = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        if (! mb_check_encoding($sample, 'UTF-8')) {
            $options->ENCODING = 'Windows-1252';
        }

        return $options;
    }

    // ---------- Contrôles ----------

    private function checkRow(int $line, array $d, array $refs, bool $createMissing, Collection $existingMatricules, Collection $existingEmails, Collection $fileMatricules, Collection $fileEmails): array
    {
        $errors = [];
        $warnings = [];

        // Excel transforme « 75002 » ou « 0612345678 » en nombres : on les relit comme du texte
        foreach ($d as $key => $value) {
            if ((is_int($value) || is_float($value)) && ! in_array($key, ['date_naissance', 'date_embauche', 'date_fin_contrat', 'salaire', 'heures_hebdo'], true)) {
                $d[$key] = is_float($value) && floor($value) == $value ? (string) (int) $value : (string) $value;
            }
        }

        $label = fn (string $key) => rtrim(preg_replace('/\s*\(.*\)/u', '', self::COLUMNS[$key]), ' *');

        foreach (['nom', 'prenom'] as $key) {
            if (! $d[$key]) {
                $errors[] = "{$label($key)} manquant.";
            } elseif (mb_strlen((string) $d[$key]) > 100) {
                $errors[] = "{$label($key)} trop long (100 caractères max.).";
            }
        }

        foreach (['date_naissance', 'date_embauche', 'date_fin_contrat'] as $key) {
            if ($d[$key] !== null) {
                $date = $this->parseDate($d[$key]);
                if (! $date) {
                    $errors[] = "{$label($key)} invalide : « {$this->display($d[$key])} » (format JJ/MM/AAAA).";
                }
                $d[$key] = $date;
            }
        }
        if (! $d['date_embauche'] && ! collect($errors)->contains(fn ($e) => str_starts_with($e, "Date d'embauche"))) {
            $errors[] = "Date d'embauche manquante.";
        }

        if ($d['matricule'] !== null) {
            $d['matricule'] = (string) $d['matricule'];
            $m = Str::upper($d['matricule']);
            if ($existingMatricules->has($m)) {
                $errors[] = "Le matricule {$d['matricule']} existe déjà.";
            } elseif (($fileMatricules[$m] ?? 0) > 1) {
                $errors[] = "Le matricule {$d['matricule']} apparaît plusieurs fois dans le fichier.";
            }
        }

        foreach (['email_pro', 'email_perso'] as $key) {
            if ($d[$key] !== null && ! filter_var($d[$key], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "{$label($key)} invalide : « {$d[$key]} ».";
            }
        }
        if ($d['email_pro'] !== null) {
            $email = Str::lower($d['email_pro']);
            if ($existingEmails->has($email)) {
                $errors[] = "L'e-mail professionnel {$d['email_pro']} est déjà utilisé.";
            } elseif (($fileEmails[$email] ?? 0) > 1) {
                $errors[] = "L'e-mail professionnel {$d['email_pro']} apparaît plusieurs fois dans le fichier.";
            }
        }

        $d['civilite'] = match (Str::lower(rtrim((string) $d['civilite'], '.'))) {
            '' => null,
            'm', 'mr', 'monsieur' => 'M.',
            'mme', 'madame', 'mlle', 'mademoiselle' => 'Mme',
            default => tap(null, function () use (&$errors, $d) { $errors[] = "Civilité inconnue : « {$d['civilite']} » (M. ou Mme)."; }),
        };

        $status = Str::slug(str_replace(["'", '’'], ' ', (string) $d['statut']), '_');
        $d['statut'] = match (true) {
            $status === '' || $status === 'actif' => 'actif',
            in_array($status, ['periode_essai', 'periode_d_essai', 'essai'], true) => 'periode_essai',
            default => tap('actif', function () use (&$errors, $d) { $errors[] = "Statut inconnu : « {$d['statut']} » (actif ou periode_essai)."; }),
        };

        // Références : département, poste, site
        foreach (['departement', 'poste', 'site'] as $key) {
            if ($d[$key] === null) {
                continue;
            }
            $d[$key] = (string) $d[$key];
            if (! $this->find($refs[$key], $key, $d[$key])) {
                if ($createMissing) {
                    $this->toCreate[$key][] = $d[$key];
                    $warnings[] = "{$label($key)} « {$d[$key]} » sera créé.";
                } else {
                    $errors[] = "{$label($key)} inconnu : « {$d[$key]} ».";
                }
            }
        }

        if ($d['manager'] !== null) {
            $d['manager'] = (string) $d['manager'];
            $m = Str::upper($d['manager']);
            if (! $existingMatricules->has($m) && ! $fileMatricules->has($m)) {
                $errors[] = "Manager introuvable : aucun employé avec le matricule {$d['manager']}.";
            } elseif ($m === Str::upper((string) $d['matricule'])) {
                $errors[] = 'Un employé ne peut pas être son propre manager.';
            }
        }

        // Contrat (facultatif)
        if ($d['type_contrat'] !== null) {
            $type = collect(Contract::TYPES)->first(fn ($t) => Str::lower($t) === Str::lower(Str::ascii((string) $d['type_contrat'])));
            if (! $type) {
                $errors[] = "Type de contrat inconnu : « {$d['type_contrat']} ».";
            }
            $d['type_contrat'] = $type;

            if ($d['salaire'] === null || ! is_numeric(str_replace([' ', ','], ['', '.'], (string) $d['salaire']))) {
                $errors[] = 'Salaire brut mensuel manquant ou invalide (obligatoire avec un type de contrat).';
            } else {
                $d['salaire'] = (float) str_replace([' ', ','], ['', '.'], (string) $d['salaire']);
            }

            if ($d['heures_hebdo'] !== null) {
                $hours = (float) str_replace(',', '.', (string) $d['heures_hebdo']);
                if ($hours >= 1 && $hours <= 48) {
                    $d['heures_hebdo'] = $hours;
                } else {
                    $errors[] = 'Heures par semaine invalides (entre 1 et 48).';
                }
            }

            if ($type && in_array($type, Contract::FIXED_TERM_TYPES, true) && ! $d['date_fin_contrat']) {
                $errors[] = "Fin du contrat obligatoire pour un {$type}.";
            }
            if ($type === 'CDI' && $d['date_fin_contrat']) {
                $errors[] = 'Un CDI n’a pas de date de fin.';
            }
            if ($d['date_fin_contrat'] && $d['date_embauche'] && $d['date_fin_contrat'] < $d['date_embauche']) {
                $errors[] = "La fin du contrat précède la date d'embauche.";
            }
        } elseif ($d['date_fin_contrat'] || $d['salaire']) {
            $warnings[] = 'Pas de type de contrat : la fin de contrat et le salaire sont ignorés.';
            $d['date_fin_contrat'] = $d['salaire'] = null;
        }

        return ['line' => $line, 'data' => $d, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** Date Excel (cellule date ou numéro de série), JJ/MM/AAAA ou AAAA-MM-JJ. */
    private function parseDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value) && strlen($value) === 5)) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        $value = trim((string) $value);
        foreach (['d/m/Y', 'd/m/y', 'd-m-Y', 'd.m.Y', 'Y-m-d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat("!{$format}", $value);
            } catch (\Throwable) {
                continue; // format différent : on essaie le suivant
            }
            // Refuse les dates « corrigées » par PHP (31/02 → 03/03)
            if ($date && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }

    private function display(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('d/m/Y') : (string) $value;
    }

    private function find(Collection $items, string $kind, ?string $value): ?object
    {
        if ($value === null) {
            return null;
        }
        $v = Str::lower(trim($value));

        return match ($kind) {
            'departement' => $items->first(fn ($d) => Str::lower($d->code) === $v || Str::lower($d->name) === $v),
            'poste' => $items->first(fn ($p) => Str::lower($p->title) === $v),
            'site' => $items->first(fn ($s) => Str::lower($s->name) === $v),
        };
    }

    private function uniqueDepartmentCode(string $name): string
    {
        $base = Str::upper(Str::substr(Str::slug($name, ''), 0, 6)) ?: 'DEP';
        $code = $base;
        for ($i = 2; Department::where('code', $code)->exists(); $i++) {
            $code = $base.$i;
        }

        return $code;
    }
}
