<?php

namespace Database\Factories;

use App\Enums\KnowledgeCategory;
use App\Enums\KnowledgeSource;
use App\Enums\KnowledgeStatus;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeEntry>
 */
class KnowledgeEntryFactory extends Factory
{
    protected $model = KnowledgeEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(),
            'content' => fake()->paragraph(),
            'category' => KnowledgeCategory::Insight->value,
            'source' => KnowledgeSource::Manual->value,
            'author' => '',
            'status' => KnowledgeStatus::Pending->value,
            'metadata' => [],
        ];
    }
}
