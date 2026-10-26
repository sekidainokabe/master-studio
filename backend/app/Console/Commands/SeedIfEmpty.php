<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 初回デプロイ時だけシードする。本番コンテナの起動のたびに実行するため、2回目以降は何もしない。
 */
#[Signature('app:seed-if-empty')]
#[Description('ユーザーが1人もいない（未シードの）ときだけ初期データを投入する')]
class SeedIfEmpty extends Command
{
    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->info('シード済みのため、何もしません。');

            return self::SUCCESS;
        }

        // 途中で失敗したときに「ユーザーだけ入った」状態が残ると、次回以降シードされなくなるため、まとめて巻き戻せるようにする
        DB::transaction(fn () => $this->call('db:seed', ['--force' => true]));

        return self::SUCCESS;
    }
}
