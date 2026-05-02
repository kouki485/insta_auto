<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 100)->comment('テナント名(店舗・ブランド・アカウント表示名)');
            $table->string('ig_username', 50)->unique();
            $table->string('ig_session_path', 255)->comment('Instagrapiセッションファイルのパス');
            $table->text('ig_password_encrypted')->nullable()->comment('Crypt 暗号化済みのパスワード');
            $table->text('proxy_url')->comment('Crypt 暗号化済みのプロキシ URL');
            $table->unsignedSmallInteger('daily_dm_limit')->default(5)
                ->comment('初期5、ウォームアップ後段階的に20まで');
            $table->unsignedSmallInteger('daily_follow_limit')->default(30);
            $table->unsignedSmallInteger('daily_like_limit')->default(100);
            $table->enum('status', ['active', 'paused', 'banned', 'warning'])->default('active');
            $table->unsignedInteger('account_age_days')->nullable()
                ->comment('IG アカウント作成からの経過日数');
            $table->string('timezone', 50)->default('Asia/Tokyo');
            $table->timestamp('warmup_started_at')->nullable()
                ->comment('ウォームアップ開始日(設計書 §4.2 ウォームアップスケジュールの起点)');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
