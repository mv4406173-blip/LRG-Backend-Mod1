<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SchemaImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SchemaImport>
 */
class SchemaImportFactory extends Factory
{
    protected $model = SchemaImport::class;

    public function definition(): array
    {
        $status    = $this->faker->randomElement(['completed', 'completed', 'completed', 'failed', 'queued']);
        $queuedAt  = $this->faker->dateTimeBetween('-30 days', '-1 day');
        $startedAt = (clone $queuedAt)->modify('+' . $this->faker->numberBetween(1, 30) . ' seconds');
        $finishedAt = in_array($status, ['completed', 'failed'])
            ? (clone $startedAt)->modify('+' . $this->faker->numberBetween(5, 120) . ' seconds')
            : null;

        return [
            'project_id'        => Project::factory(),
            'requested_by'      => User::factory(),
            'original_filename' => $this->faker->randomElement([
                'schema.sql', 'database.sql', 'dump.sql', 'migration.sql', 'backup.sql',
            ]),
            'storage_path'      => 'schema_imports/test/' . Str::uuid() . '.sql',
            'file_sha256'       => hash('sha256', Str::random(100)),
            'file_size_bytes'   => $this->faker->numberBetween(512, 500000),
            'dialect'           => $this->faker->randomElement(['mysql', 'mysql', 'postgresql', 'mariadb']),
            'status'            => $status,
            'attempt_count'     => $this->faker->numberBetween(1, 3),
            'parser_version'    => '1.0.0',
            'error_code'        => $status === 'failed' ? 'PROCESSING_ERROR' : null,
            'error_summary'     => $status === 'failed' ? 'Syntax error near unexpected token' : null,
            'queued_at'         => $queuedAt,
            'started_at'        => $startedAt,
            'finished_at'       => $finishedAt,
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => 'completed', 'error_code' => null, 'error_summary' => null]);
    }

    public function failed(): static
    {
        return $this->state([
            'status'        => 'failed',
            'error_code'    => 'PROCESSING_ERROR',
            'error_summary' => 'SQL syntax error: unexpected token at line 42',
        ]);
    }
}
