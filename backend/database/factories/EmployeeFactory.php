<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        $civility = fake()->randomElement(['M.', 'Mme']);
        $firstName = $civility === 'M.' ? fake()->firstNameMale() : fake()->firstNameFemale();
        $lastName = fake()->lastName();

        return [
            'civility' => $civility,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => fake()->dateTimeBetween('-60 years', '-20 years'),
            'birth_place' => fake()->city(),
            'nationality' => 'Française',
            'marital_status' => fake()->randomElement(['celibataire', 'marie', 'pacse']),
            'children_count' => fake()->numberBetween(0, 3),
            'personal_email' => fake()->unique()->safeEmail(),
            'work_email' => fake()->unique()->userName().'@entreprise.test',
            'phone' => fake()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_phone' => fake()->phoneNumber(),
            'hire_date' => fake()->dateTimeBetween('-10 years', '-1 month'),
            'status' => fake()->randomElement(['actif', 'actif', 'actif', 'actif', 'periode_essai']),
        ];
    }
}
