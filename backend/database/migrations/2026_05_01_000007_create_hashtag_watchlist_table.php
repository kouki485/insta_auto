<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hashtag_watchlist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();
            $table->string('hashtag', 100)->comment('# は含めない');
            $table->string('language', 10)->nullable();
            $table->unsignedTinyInteger('priority')->default(5)->comment('1-10、高いほど優先');
            $table->boolean('active')->default(true);
            $table->timestamp('last_scraped_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['account_id', 'hashtag'], 'uq_account_hashtag');
            $table->index(['active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hashtag_watchlist');
    }
};
