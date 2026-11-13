<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Employés de démonstration au contexte béninois (Faker n'a pas de locale pour le Bénin).
 *
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    private const MALE_NAMES = ['Koffi', 'Sènan', 'Mahougnon', 'Rodrigue', 'Fiacre', 'Gildas', 'Ulrich', 'Romaric', 'Hervé', 'Wilfried', 'Ibrahim', 'Moussa', 'Bio', 'Séraphin', 'Parfait', 'Expédit'];

    private const FEMALE_NAMES = ['Afi', 'Sèna', 'Fifamè', 'Mahugnon', 'Bénédicte', 'Carine', 'Estelle', 'Nadège', 'Prisca', 'Rachidath', 'Aïchatou', 'Mariam', 'Yvette', 'Pélagie', 'Grâce', 'Edwige'];

    private const LAST_NAMES = [
        'Adjovi', 'Agossou', 'Ahouandjinou', 'Akpovi', 'Amoussou', 'Assogba', 'Azonhiho', 'Bossou', 'Chabi', 'Dansou', 'Djossou', 'Fagbohoun',
        'Gbaguidi', 'Gnonlonfoun', 'Hounkpatin', 'Houngbo', 'Kiki', 'Kpadonou', 'Lawani', 'Mensah', 'Orou', 'Quenum', 'Sagbo', 'Soglo',
        'Tchibozo', 'Togbé', 'Yessoufou', 'Zannou', 'Zinsou', 'Bio Tchané',
    ];

    private const CITIES = ['Cotonou', 'Porto-Novo', 'Abomey-Calavi', 'Parakou', 'Ouidah', 'Bohicon', 'Abomey', 'Lokossa', 'Natitingou', 'Djougou'];

    private const DISTRICTS = ['Cadjèhoun', 'Fidjrossè', 'Akpakpa', 'Gbégamey', 'Zogbo', 'Agla', 'Vèdoko', 'Sainte-Rita', 'Godomey', 'Togoudo'];

    public function definition(): array
    {
        $civility = fake()->randomElement(['M.', 'Mme']);
        $firstName = fake()->randomElement($civility === 'M.' ? self::MALE_NAMES : self::FEMALE_NAMES);
        $lastName = fake()->randomElement(self::LAST_NAMES);
        $city = fake()->randomElement(self::CITIES);
        $login = Str::slug($firstName.' '.$lastName, '.');

        return [
            'civility' => $civility,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => fake()->dateTimeBetween('-60 years', '-20 years'),
            'birth_place' => fake()->randomElement(self::CITIES),
            'nationality' => 'Béninoise',
            'marital_status' => fake()->randomElement(['celibataire', 'marie', 'marie']),
            'children_count' => fake()->numberBetween(0, 4),
            'personal_email' => fake()->unique()->numerify($login.'###@gmail.com'),
            'work_email' => fake()->unique()->numerify($login.'##@entreprise.test'),
            'phone' => self::phone(),
            'address' => 'Lot '.fake()->numberBetween(10, 2500).', quartier '.fake()->randomElement(self::DISTRICTS),
            'city' => $city,
            'postal_code' => fake()->numerify('0% BP ####'),
            'emergency_contact_name' => fake()->randomElement([...self::MALE_NAMES, ...self::FEMALE_NAMES]).' '.fake()->randomElement(self::LAST_NAMES),
            'emergency_contact_phone' => self::phone(),
            'hire_date' => fake()->dateTimeBetween('-10 years', '-1 month'),
            'status' => fake()->randomElement(['actif', 'actif', 'actif', 'actif', 'periode_essai']),
        ];
    }

    /** Numéro mobile béninois à 10 chiffres (préfixe 01 depuis novembre 2024). */
    private static function phone(): string
    {
        return '+229 01 '.fake()->randomElement(['97', '96', '95', '66', '67', '61', '90', '91']).fake()->numerify(' ## ## ##');
    }
}
