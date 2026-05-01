<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();
            $table->enum('event_type', [
                'challenge_required',
                'login_failed',
                'rate_limited',
                'feedback_required',
                'action_blocked',
                'checkpoint',
                'auto_paused',
                'manual_resumed',
            ]);
            $table->enum('severity', ['info', 'warning', 'critical']);
            $table->json('details')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['account_id', 'occurred_at'], 'idx_account_occurred');
            $table->index(['severity', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_events');
    }
};
