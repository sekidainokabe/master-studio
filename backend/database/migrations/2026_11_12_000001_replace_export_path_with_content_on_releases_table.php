<?php

use App\Services\ReleaseExport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 出力 JSON をファイル（export_path）ではなく DB（content）に保存する。
 * 本番（Railway）のディスクは再デプロイで消えるため（docs/plan-week6.md）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn('export_path');
            // 古いリリースの内容はあとから作り直せないため、作れなかったものは null のままにする
            $table->longText('content')->nullable()->after('released_by');
        });

        // 今の本番データは、いつも最新のリリースの状態と同じ。そのため最新のリリースの本文だけは、ここで作れる
        $latest = DB::table('releases')->orderByDesc('version')->first();
        if ($latest !== null) {
            DB::table('releases')->where('id', $latest->id)->update([
                'content' => app(ReleaseExport::class)->content($latest->version, Carbon::parse($latest->created_at)),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn('content');
            $table->string('export_path')->nullable()->after('released_by');
        });
    }
};
