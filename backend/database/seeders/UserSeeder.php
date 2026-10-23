<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * 権限ごとのデモ用ユーザー。パスワードはすべて "password"（モデルの casts でハッシュ化される）
     */
    public function run(): void
    {
        $users = [
            ['name' => '編集 太郎', 'email' => 'editor@example.com', 'role' => UserRole::Editor],
            ['name' => '承認 花子', 'email' => 'approver@example.com', 'role' => UserRole::Approver],
            ['name' => '管理 次郎', 'email' => 'admin@example.com', 'role' => UserRole::Admin],
        ];

        foreach ($users as $user) {
            User::create([...$user, 'password' => 'password']);
        }
    }
}
