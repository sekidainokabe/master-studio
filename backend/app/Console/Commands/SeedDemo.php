<?php

namespace App\Console\Commands;

use App\Models\Changeset;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * デモ用のデータを、まだ入っていないときだけ入れる。本番コンテナの起動のたびに実行するため、何度実行しても1回分だけになる
 */
#[Signature('app:seed-demo')]
#[Description('デモ用の変更セット（反映済み1つ・エラー入りの下書き1つ）を、まだないときだけ入れる')]
class SeedDemo extends Command
{
    public function handle(): int
    {
        if (! User::query()->exists()) {
            $this->warn('初期データがないため、何もしません（先に app:seed-if-empty を実行してください）。');

            return self::SUCCESS;
        }
        if (Changeset::where('title', DemoSeeder::APPLIED_TITLE)->exists()) {
            $this->info('デモ用データは入れ済みのため、何もしません。');

            return self::SUCCESS;
        }

        // 反映済みだけ入って下書きがない、のような中途半端な状態を残さない
        DB::transaction(fn () => $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]));

        return self::SUCCESS;
    }
}
