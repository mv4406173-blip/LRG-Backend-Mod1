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
        Schema::create('auth_security_events', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->string('event_type', 60);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->json('metadata')->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')
            ->references('id')
            ->on('users')
            ->nullOnDelete()
            ->cascadeOnUpdate();

            $table->index(['user_id', 'occurred_at'], 'idx_auth_events_user_time');
            $table->index(['event_type', 'occurred_at'], 'idx_auth_events_type_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auth_security_events');
    }
};
