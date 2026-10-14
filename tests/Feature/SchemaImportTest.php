<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SchemaArtifact;
use App\Models\SchemaImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchemaImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_sql_file_is_queued_and_published_as_an_artifact(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->active()->create();

        $sql = <<<'SQL'
CREATE TABLE courts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (id)
);
SQL;

        $file = UploadedFile::fake()->createWithContent('courts.sql', $sql);

        $this->actingAs($user)
            ->post('/api/v1/projects/'.$project->id.'/schema-imports', [
                'file' => $file,
                'dialect' => 'mysql',
            ], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');

        $import = SchemaImport::first();
        $this->assertNotNull($import);
        $this->assertSame(SchemaImport::STATUS_COMPLETED, $import->fresh()->status);
        $this->assertSame(1, SchemaArtifact::count());

        $artifact = SchemaArtifact::first();
        $this->assertSame('LRG-Schema', $artifact->schema_document['format']);
        $this->assertSame('courts', $artifact->schema_document['tables'][0]['name']);
        $this->assertStringNotContainsString('storage_path', json_encode(
            $this->actingAs($user)->getJson('/api/v1/projects/'.$project->id.'/schema-imports/'.$import->id)->json()
        ));
    }

    public function test_invalid_sql_does_not_replace_a_previous_valid_artifact(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->active()->create();

        $valid = UploadedFile::fake()->createWithContent('ok.sql', 'CREATE TABLE users (id INT NOT NULL, PRIMARY KEY (id));');
        $this->actingAs($user)->post('/api/v1/projects/'.$project->id.'/schema-imports', [
            'file' => $valid,
            'dialect' => 'mysql',
        ], ['Accept' => 'application/json'])->assertStatus(202);

        $this->assertSame(1, SchemaArtifact::count());

        $invalid = UploadedFile::fake()->createWithContent('bad.sql', 'this is not sql');
        $this->actingAs($user)->post('/api/v1/projects/'.$project->id.'/schema-imports', [
            'file' => $invalid,
            'dialect' => 'mysql',
        ], ['Accept' => 'application/json'])->assertStatus(202);

        $this->assertSame(1, SchemaArtifact::count());
        $this->assertSame(SchemaImport::STATUS_FAILED, SchemaImport::orderByDesc('id')->first()->status);
    }

    public function test_archived_projects_reject_new_imports(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->archived()->create();
        $file = UploadedFile::fake()->createWithContent('schema.sql', 'CREATE TABLE t (id INT);');

        $this->actingAs($user)
            ->post('/api/v1/projects/'.$project->id.'/schema-imports', [
                'file' => $file,
                'dialect' => 'mysql',
            ], ['Accept' => 'application/json'])
            ->assertStatus(409);
    }

    public function test_empty_sql_files_are_rejected(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->active()->create();

        $this->actingAs($user)
            ->post('/api/v1/projects/'.$project->id.'/schema-imports', [
                'file' => UploadedFile::fake()->createWithContent('empty.sql', ''),
                'dialect' => 'mysql',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }
}
