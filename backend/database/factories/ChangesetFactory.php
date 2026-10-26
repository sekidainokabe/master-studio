<?php

namespace Database\Factories;

use App\Enums\ChangesetStatus;
use App\Models\Changeset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Changeset>
 */
class ChangesetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => ChangesetStatus::Draft,
            'author_id' => User::factory(),
        ];
    }

    public function status(ChangesetStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
