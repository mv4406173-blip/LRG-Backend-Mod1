<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SchemaArtifact;
use App\Models\SchemaImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_creates_at_least_twenty_records_and_is_repeatable(): void
    {
        Storage::fake('local');

        $this->seed();

        $this->assertSame(8, User::count());
        $this->assertSame(20, Project::count());
        $this->assertSame(6, SchemaImport::count());
        $this->assertSame(4, SchemaArtifact::count());

        $reservas = Project::where('name', 'Sistema de Reservas de Canchas')->firstOrFail();
        $artifact = SchemaArtifact::where('project_id', $reservas->id)
            ->where('version', 1)
            ->firstOrFail();

        $this->assertNotEmpty($artifact->schema_document['relationships']);
        $this->assertGreaterThanOrEqual(38, User::count() + Project::count() + SchemaImport::count() + SchemaArtifact::count());

        $this->seed();

        $this->assertSame(8, User::count());
        $this->assertSame(20, Project::count());
        $this->assertSame(6, SchemaImport::count());
        $this->assertSame(4, SchemaArtifact::count());
    }
}
