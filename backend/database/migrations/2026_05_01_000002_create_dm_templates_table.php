<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();
            $table->string('language', 10)->comment('ISO 639-1');
            $table->text('template')->comment('プレースホルダ: {username}, {store_name}');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['account_id', 'language'], 'uq_account_lang');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_templates');
    }
};
