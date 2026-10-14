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
        Schema::create('schema_import_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('import_id');
            $table->string('severity', 20);
            $table->string('code', 100);
            $table->string('message', 2000);
            $table->json('source_location')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['import_id', 'severity'],
                'idx_schema_diagnostics_import_severity'
            );
            $table->foreign('import_id', 'fk_schema_diagnostics_import_id')
                ->references('id')
                ->on('schema_imports')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE schema_import_diagnostics ADD CONSTRAINT chk_schema_diagnostics_severity CHECK (severity IN ('info', 'warning', 'error', 'critical'))"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schema_import_diagnostics');
    }
};
