<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('action', 50);
            $table->string('target_type', 50);
            $table->unsignedBigInteger('target_id');
            $table->json('detail')->nullable();
            // 操作履歴は追記のみで変更しないので updated_at は持たない
            $table->timestamp('created_at')->useCurrent();

            // 「この変更セットの履歴」のような対象ごとの検索を速くする
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
