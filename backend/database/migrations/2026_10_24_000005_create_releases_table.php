<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            // シードで作る初期リリースは変更セットを経由しないため null を許す
            $table->foreignId('changeset_id')->nullable()->unique()->constrained();
            $table->foreignId('released_by')->constrained('users');
            $table->string('export_path')->nullable();
            // リリースは作成後に変更しないので updated_at は持たない
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
