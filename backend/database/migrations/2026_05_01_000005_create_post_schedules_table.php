<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();
            $table->enum('type', ['feed', 'story']);
            $table->string('image_path', 255)->comment('事前アップロード済み画像パス');
            $table->text('caption')->nullable();
            $table->json('text_overlay')->nullable()->comment('ストーリーズ用テキスト合成設定');
            $table->timestamp('scheduled_at');
            $table->timestamp('posted_at')->nullable();
            $table->string('ig_media_id', 64)->nullable();
            $table->enum('status', ['scheduled', 'posting', 'posted', 'failed'])
                ->default('scheduled');
            $table->text('error_message')->nullable();
            $table->string('worker_job_id', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at'], 'idx_scheduled');
            $table->index('worker_job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_schedules');
    }
};
