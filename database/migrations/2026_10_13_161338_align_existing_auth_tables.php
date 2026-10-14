<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 255)->change();
            $table->timestamp('email_verified_at')->nullable()->default(null)->change();
        });

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('tokenable_type', 255);
                $table->unsignedBigInteger('tokenable_id');
                $table->string('name', 255);
                $table->char('token', 64)->unique('uq_personal_access_tokens_token');
                $table->index(
                    ['tokenable_type', 'tokenable_id'],
                    'idx_personal_access_tokens_owner'
                );
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->index('expires_at', 'idx_personal_access_tokens_expires_at');
                $table->timestamps();
            });

            return;
        }

        $indexes = collect(Schema::getIndexes('personal_access_tokens'))
            ->pluck('name');

        Schema::table('personal_access_tokens', function (Blueprint $table) use ($indexes) {
            if (! $indexes->contains('uq_personal_access_tokens_token')) {
                $table->unique('token', 'uq_personal_access_tokens_token');
            }

            if (! $indexes->contains('idx_personal_access_tokens_owner')) {
                $table->index(
                    ['tokenable_type', 'tokenable_id'],
                    'idx_personal_access_tokens_owner'
                );
            }

            if (! $indexes->contains('idx_personal_access_tokens_expires_at')) {
                $table->index('expires_at', 'idx_personal_access_tokens_expires_at');
            }
        });
    }

    /**
     * These changes intentionally widen/fix existing columns and should not be
     * reversed automatically, as a rollback could truncate stored email data.
     */
    public function down(): void
    {
    }
};
