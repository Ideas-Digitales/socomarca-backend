<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        $rut = fake()->numberBetween(10000000, 25000000);

        return [
            'user_id' => User::factory(),
            'entity_code' => (string) fake()->numberBetween(10000000, 99999999),
            'rut' => $rut.'-'.$this->calculateDv($rut),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => (string) fake()->numberBetween(900000000, 999999999),
            'fax' => null,
            'position' => null,
            'area' => null,
            'authorized' => true,
            'address' => mb_substr(fake()->streetAddress(), 0, 50),
            'random_synced_at' => now(),
        ];
    }

    private function calculateDv(int $rut): string
    {
        $numero = (string) $rut;
        $suma = 0;
        $multiplo = 2;

        for ($i = strlen($numero) - 1; $i >= 0; $i--) {
            $suma += (int) $numero[$i] * $multiplo;
            $multiplo = $multiplo < 7 ? $multiplo + 1 : 2;
        }

        $dv = 11 - ($suma % 11);

        return match ($dv) {
            11 => '0',
            10 => 'K',
            default => (string) $dv,
        };
    }
}
