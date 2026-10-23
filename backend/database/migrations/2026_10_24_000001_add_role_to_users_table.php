<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name', 100)->change();
            // 値は App\Enums\UserRole と対応。マイグレーションは過去の時点の定義を固定で持つため、Enum クラスは参照しない
            $table->enum('role', ['editor', 'approver', 'admin'])->default('editor')->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
            $table->string('name')->change();
        });
    }
};
