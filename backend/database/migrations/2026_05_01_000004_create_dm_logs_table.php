<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();
            $table->foreignId('prospect_id')
                ->constrained('prospects')
                ->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()
                ->constrained('dm_templates')
                ->nullOnDelete();
            $table->string('language', 10);
            $table->text('message_sent')->comment('AI生成後の最終文面');
            $table->enum('status', ['queued', 'sent', 'failed', 'rate_limited', 'blocked'])
                ->default('queued');
            $table->text('error_message')->nullable();
            $table->string('worker_job_id', 64)->nullable()
                ->comment('Worker キューに投入した job_id (UUID v4)');
            $table->string('ig_message_id', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'sent_at'], 'idx_account_sent');
            $table->index('status');
            $table->index('worker_job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_logs');
    }
};
