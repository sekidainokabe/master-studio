<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_columns', function (Blueprint $table) {
            $table->id();
            // 列定義はマスタの一部なので、マスタごと消すときは一緒に消す
            $table->foreignId('master_table_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50);
            $table->string('name', 100);
            $table->enum('type', ['int', 'float', 'string', 'bool', 'enum', 'ref']);
            $table->boolean('is_required')->default(false);
            $table->decimal('min_value', 12, 4)->nullable();
            $table->decimal('max_value', 12, 4)->nullable();
            $table->unsignedInteger('max_length')->nullable();
            $table->json('enum_values')->nullable();
            // 参照先マスタが参照されている間は消せないようにする
            $table->foreignId('ref_master_table_id')->nullable()->constrained('master_tables')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['master_table_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_columns');
    }
};
