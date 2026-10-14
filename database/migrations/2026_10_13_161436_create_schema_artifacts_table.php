<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schema_artifacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('import_id');
            $table->unsignedInteger('version');
            $table->string('format_name', 50)->default('LRG-Schema');
            $table->string('format_version', 20)->default('1.0');
            $table->json('schema_document');
            $table->char('artifact_sha256', 64);
            $table->string('parser_version', 30);
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['project_id', 'version'], 'uq_schema_artifacts_project_version');
            $table->unique('import_id', 'uq_schema_artifacts_import');
            $table->index(['project_id', 'created_at'], 'idx_schema_artifacts_project_created');

            $table->foreign('project_id', 'fk_schema_artifacts_project_id')
                ->references('id')
                ->on('projects')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('import_id', 'fk_schema_artifacts_import_id')
                ->references('id')
                ->on('schema_imports')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('created_by', 'fk_schema_artifacts_created_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE schema_artifacts ADD CONSTRAINT chk_schema_artifacts_version CHECK (version >= 1)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schema_artifacts');
    }
};
