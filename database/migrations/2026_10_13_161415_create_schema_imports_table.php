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
        Schema::create('schema_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('requested_by');

            $table->string('original_filename', 255);
            $table->string('storage_path', 1024);
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('file_size_bytes');

            $table->string('dialect', 30);
            $table->string('status', 20)->default('queued');

            $table->string('idempotency_key', 100)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);

            $table->string('parser_version', 30)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_summary', 1000)->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['project_id', 'idempotency_key'],
                'uq_schema_imports_idempotency'
            );

            $table->index(['project_id', 'created_at'], 'idx_schema_imports_project_created');
            $table->index(['status', 'created_at'], 'idx_schema_imports_status_created');
            $table->index('requested_by', 'idx_schema_imports_requester');

            $table->foreign('project_id', 'fk_schema_imports_project_id')
                ->references('id')
                ->on('projects')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('requested_by', 'fk_schema_imports_requested_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE schema_imports ADD CONSTRAINT chk_schema_imports_dialect CHECK (dialect IN ('mysql', 'mariadb', 'postgresql', 'sqlite'))"
            );
            DB::statement(
                "ALTER TABLE schema_imports ADD CONSTRAINT chk_schema_imports_status CHECK (status IN ('queued', 'processing', 'completed', 'failed', 'cancelled'))"
            );
            DB::statement(
                'ALTER TABLE schema_imports ADD CONSTRAINT chk_schema_imports_size CHECK (file_size_bytes > 0)'
            );
            DB::statement(
                'ALTER TABLE schema_imports ADD CONSTRAINT chk_schema_imports_attempts CHECK (attempt_count <= 10)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schema_imports');
    }
};
