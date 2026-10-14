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
        if (Schema::hasTable('sessions')) {
            $foreignKeys = Schema::getForeignKeys('sessions');
            $hasUserForeignKey = collect($foreignKeys)->contains(
                fn (array $foreignKey): bool => $foreignKey['columns'] === ['user_id']
                    && $foreignKey['foreign_table'] === 'users'
            );

            if (! $hasUserForeignKey) {
                if (DB::connection()->getDriverName() === 'sqlite') {
                    Schema::rename('sessions', 'sessions_legacy');
                    $this->createSessionsTable();

                    DB::table('sessions_legacy')
                        ->orderBy('id')
                        ->chunkById(500, function ($rows): void {
                            DB::table('sessions')->insert(
                                $rows->map(fn ($row): array => [
                                    'id' => $row->id,
                                    'user_id' => $row->user_id,
                                    'ip_address' => $row->ip_address,
                                    'user_agent' => $row->user_agent,
                                    'payload' => $row->payload,
                                    'last_activity' => $row->last_activity,
                                ])->all()
                            );
                        });

                    Schema::drop('sessions_legacy');
                } else {
                    Schema::table('sessions', function (Blueprint $table) {
                        $table->foreign('user_id', 'fk_sessions_user_id')
                            ->references('id')
                            ->on('users')
                            ->nullOnDelete()
                            ->cascadeOnUpdate();
                    });
                }
            }

            return;
        }

        $this->createSessionsTable();
    }

    private function createSessionsTable(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity');
            $table->foreignId('user_id')->nullable()->index('idx_sessions_user_id');
            $table->index('last_activity', 'idx_sessions_last_activity');
            $table->foreign('user_id', 'fk_sessions_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
