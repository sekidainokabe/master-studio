<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('changesets', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'in_review', 'rejected', 'applied'])->default('draft')->index();
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('reviewer_id')->nullable()->constrained('users');
            // releases は changesets を参照するため、ここではまだ外部キーを張れない。
            // 両テーブル作成後の add_base_release_foreign_key_to_changesets で追加する
            $table->unsignedBigInteger('base_release_id')->nullable();
            $table->text('review_comment')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changesets');
    }
};
