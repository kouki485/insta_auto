<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();
            $table->string('ig_user_id', 50)->comment('Instagram内部ユーザーID');
            $table->string('ig_username', 50);
            $table->string('full_name', 100)->nullable();
            $table->text('bio')->nullable();
            $table->unsignedInteger('follower_count')->default(0);
            $table->unsignedInteger('following_count')->default(0);
            $table->unsignedInteger('post_count')->default(0);
            $table->string('detected_lang', 10)->nullable()
                ->comment('ISO 639-1: en, zh, ko, th, fr, es ...');
            $table->string('source_hashtag', 100)->nullable()->comment('抽出元ハッシュタグ');
            $table->string('source_post_url', 255)->nullable();
            $table->boolean('is_tourist')->default(false)->comment('観光客判定結果');
            $table->unsignedTinyInteger('tourist_score')->nullable()->comment('観光客スコア 0-100');
            $table->enum('status', [
                'new', 'queued', 'dm_sent', 'replied', 'skipped', 'blacklisted',
            ])->default('new');
            $table->timestamp('found_at')->useCurrent();
            $table->timestamp('dm_sent_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'ig_user_id'], 'uq_account_iguser');
            $table->index(['account_id', 'status'], 'idx_status_account');
        });

        // 設計書 §2.2.2 idx_tourist_score (account_id, tourist_score DESC).
        // Laravel Blueprint は降順インデックスを直接サポートしないため、生 SQL で作成する.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('CREATE INDEX idx_tourist_score ON prospects (account_id, tourist_score DESC)');
        } else {
            // SQLite は DESC インデックスを使うとクエリプランナがほぼ無視するが、
            // 互換性のため通常インデックスを作成する.
            Schema::table('prospects', function (Blueprint $table) {
                $table->index(['account_id', 'tourist_score'], 'idx_tourist_score');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prospects');
    }
};
