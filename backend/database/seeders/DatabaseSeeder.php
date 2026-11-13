<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Données de démonstration. Mot de passe de tous les comptes : password
     */
    public function run(): void
    {
        foreach (User::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $siege = Site::create(['name' => 'Siège', 'city' => 'Paris', 'country' => 'FR']);
        $agence = Site::create(['name' => 'Agence Lyon', 'city' => 'Lyon', 'country' => 'FR']);

        $departments = collect([
            ['name' => 'Direction générale', 'code' => 'DG'],
            ['name' => 'Ressources humaines', 'code' => 'RH'],
            ['name' => 'Informatique', 'code' => 'IT'],
            ['name' => 'Commercial', 'code' => 'COM'],
            ['name' => 'Finance', 'code' => 'FIN'],
        ])->mapWithKeys(fn ($d) => [$d['code'] => Department::create($d)]);

        $positions = collect([
            ['title' => 'Directeur général', 'category' => 'Cadre'],
            ['title' => 'Responsable RH', 'category' => 'Cadre'],
            ['title' => 'Chef de projet', 'category' => 'Cadre'],
            ['title' => 'Développeur', 'category' => 'Cadre'],
            ['title' => 'Commercial', 'category' => 'ETAM'],
            ['title' => 'Comptable', 'category' => 'ETAM'],
            ['title' => 'Assistant administratif', 'category' => 'Employé'],
        ])->mapWithKeys(fn ($p) => [$p['title'] => Position::create($p)]);

        // Comptes de démonstration, un par rôle
        $make = function (string $role, string $email, string $first, string $last, string $dept, string $pos, ?Employee $manager = null) use ($departments, $positions, $siege) {
            $user = User::create(['name' => "$first $last", 'email' => $email, 'password' => 'password']);
            $user->assignRole($role);

            return Employee::factory()->create([
                'user_id' => $user->id,
                'first_name' => $first,
                'last_name' => $last,
                'work_email' => $email,
                'department_id' => $departments[$dept]->id,
                'position_id' => $positions[$pos]->id,
                'site_id' => $siege->id,
                'manager_id' => $manager?->id,
                'status' => 'actif',
            ]);
        };

        User::create(['name' => 'Administrateur', 'email' => 'admin@entreprise.test', 'password' => 'password'])
            ->assignRole(User::ROLE_ADMIN);

        $dg = $make(User::ROLE_DIRECTION, 'direction@entreprise.test', 'Claire', 'Martin', 'DG', 'Directeur général');
        $rh = $make(User::ROLE_RH, 'rh@entreprise.test', 'Sophie', 'Bernard', 'RH', 'Responsable RH', $dg);
        $manager = $make(User::ROLE_MANAGER, 'manager@entreprise.test', 'Thomas', 'Petit', 'IT', 'Chef de projet', $dg);
        $make(User::ROLE_EMPLOYE, 'employe@entreprise.test', 'Lucas', 'Durand', 'IT', 'Développeur', $manager);

        $departments['DG']->update(['manager_id' => $dg->id]);
        $departments['RH']->update(['manager_id' => $rh->id]);
        $departments['IT']->update(['manager_id' => $manager->id]);

        // Équipe du manager + autres employés
        Employee::factory(6)->create([
            'department_id' => $departments['IT']->id,
            'position_id' => $positions['Développeur']->id,
            'site_id' => $siege->id,
            'manager_id' => $manager->id,
        ]);

        foreach (['COM' => 'Commercial', 'FIN' => 'Comptable', 'RH' => 'Assistant administratif'] as $code => $title) {
            Employee::factory(4)->create([
                'department_id' => $departments[$code]->id,
                'position_id' => $positions[$title]->id,
                'site_id' => fake()->randomElement([$siege->id, $agence->id]),
                'manager_id' => $dg->id,
            ]);
        }

        $this->call([ContractSeeder::class, LeaveSeeder::class, DocumentSeeder::class, AttendanceSeeder::class, PayrollSeeder::class]);
    }
}
