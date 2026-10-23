<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * changesets と releases はお互いを参照するため、両方を作ったあとで外部キーを追加する。
     */
    public function up(): void
    {
        Schema::table('changesets', function (Blueprint $table) {
            $table->foreign('base_release_id')->references('id')->on('releases');
        });
    }

    public function down(): void
    {
        Schema::table('changesets', function (Blueprint $table) {
            $table->dropForeign(['base_release_id']);
        });
    }
};
