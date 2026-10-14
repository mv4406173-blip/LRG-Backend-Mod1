<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_only_sees_their_own_projects(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $mine = Project::factory()->for($owner, 'owner')->active()->create(['name' => 'Reservas']);
        Project::factory()->for($stranger, 'owner')->active()->create(['name' => 'Ajeno']);

        $this->actingAs($owner)
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_foreign_projects_are_hidden_with_404(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        $this->actingAs($stranger)
            ->getJson('/api/v1/projects/'.$project->id)
            ->assertNotFound();
    }

    public function test_owner_is_taken_from_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/projects', [
                'name' => 'CRM',
                'owner_id' => $other->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'CRM');

        $this->assertDatabaseHas('projects', [
            'name' => 'CRM',
            'owner_id' => $user->id,
        ]);
    }

    public function test_delete_archives_the_project_without_soft_deleting_it(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->active()->create();

        $this->actingAs($user)
            ->deleteJson('/api/v1/projects/'.$project->id)
            ->assertNoContent();

        $this->assertSame('archived', $project->fresh()->status);
        $this->assertNull($project->fresh()->deleted_at);
    }
}
