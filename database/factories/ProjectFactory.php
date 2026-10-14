<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    private static array $projectNames = [
        'Sistema de Reservas',
        'E-Commerce Platform',
        'CRM Empresarial',
        'Gestión de Inventario',
        'Portal de Empleados',
        'Sistema Hospitalario',
        'Plataforma Educativa',
        'Marketplace Digital',
        'Sistema de Facturación',
        'Red Social Corporativa',
        'App de Delivery',
        'Sistema de Turnos',
        'Biblioteca Digital',
        'Control de Acceso',
        'Gestión de Proyectos',
    ];

    public function definition(): array
    {
        $name = $this->faker->unique()->randomElement(self::$projectNames)
            . ' v' . $this->faker->numberBetween(1, 3);

        return [
            'owner_id'    => User::factory(),
            'name'        => $name,
            'slug'        => Str::slug($name) . '-' . Str::random(6),
            'description' => $this->faker->optional(0.7)->paragraph(),
            'status'      => $this->faker->randomElement(['active', 'active', 'active', 'archived']),
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active']);
    }

    public function archived(): static
    {
        return $this->state(['status' => 'archived']);
    }
}
