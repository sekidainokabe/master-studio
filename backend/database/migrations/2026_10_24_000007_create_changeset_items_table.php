<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('changeset_items', function (Blueprint $table) {
            $table->id();
            // 下書きの変更セットを削除したら、その中の変更もまとめて消す
            $table->foreignId('changeset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('master_table_id')->constrained();
            $table->string('record_key', 50);
            $table->enum('operation', ['create', 'update', 'delete']);
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->timestamps();

            // 1つの変更セットの中では、1レコードにつき変更は1件にまとめる
            $table->unique(['changeset_id', 'master_table_id', 'record_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changeset_items');
    }
};
