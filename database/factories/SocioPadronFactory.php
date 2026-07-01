<?php

namespace Database\Factories;

use App\Models\SocioPadron;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocioPadron>
 */
class SocioPadronFactory extends Factory
{
    protected $model = SocioPadron::class;

    public function definition(): array
    {
        $dni = (string) fake()->unique()->numerify('########');

        return [
            'dni' => $dni,
            'sid' => 'SID-' . $dni,
            'apynom' => fake()->lastName() . ', ' . fake()->firstName(),
            'barcode' => 'BAR-' . $dni,
            'saldo' => 0,
            'semaforo' => 1,
            'ult_impago' => 0,
            'acceso_full' => true,
            'hab_controles' => 1,
            'hab_controles_raw' => [],
            'raw' => [],
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'acceso_full' => false,
            'hab_controles' => 0,
        ]);
    }
}
