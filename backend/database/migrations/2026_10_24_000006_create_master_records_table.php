<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_table_id')->constrained();
            $table->string('record_key', 50);
            $table->json('data');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->foreignId('release_id')->nullable()->constrained();
            $table->timestamps();

            // 参照列は record_key を指すため、マスタ内で一意にする
            $table->unique(['master_table_id', 'record_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_records');
    }
};
