<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'    => $this->faker->name(),
            'phone'   => '91' . $this->faker->numerify('##########'),
            'email'   => $this->faker->safeEmail(),
            'company' => $this->faker->optional()->company(),
            'notes'   => $this->faker->optional()->sentence(),
            'status'  => 'active',
        ];
    }
}