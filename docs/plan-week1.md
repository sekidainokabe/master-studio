# 第1週 作業計画（環境構築）※承認済み（2026-10-23）

## 範囲

- backend に Laravel を Sail で構築し、MySQL 8 に接続する
- GET /api/health で JSON を返す
- frontend に Vite + React + TypeScript を構築し、Vite のプロキシ経由で /api/health の結果を画面に表示する
- GitHub Actions で frontend の型チェックと backend の PHPUnit を実行する

## 前提

- プロジェクトは WSL の `~/master-studio` に置き、Claude Code も WSL 内で起動する（Windows 側のパスにある `#` や日本語は Vite などで不具合の原因になりやすいため）
- `SPEC.md` はルートにあるので、ステップ1で `docs/SPEC.md` に移す（CLAUDE.md の参照先に合わせる）

## 手動作業（開発者が行う）

| タイミング | 作業 | 確認方法 |
|---|---|---|
| 着手前 | Docker Desktop の Settings → Resources → WSL integration で Ubuntu を有効化 | WSL で `docker run --rm hello-world` が成功する |
| 着手前 | WSL に Node.js LTS を入れる（nvm 推奨） | `node -v` / `npm -v` |
| 着手前 | WSL で git の `user.name` / `user.email` を設定し、GitHub 認証（`gh auth login` か SSH 鍵） | `gh auth status` か `ssh -T git@github.com` |
| 着手前 | Windows 側で 80 / 3306 番ポートが使われていないか確認 | Sail の起動に失敗しない |
| 着手前 | フォルダを WSL へ移し、WSL 内で Claude Code を起動 | `pwd` が `/home/...` |
| ステップ5の前 | GitHub に空のリポジトリ `master-studio` を作成（README なし、public 推奨） | リポジトリのページが表示される |

## 作業手順

### ステップ1：リポジトリの雛形

- `git init`、`.gitignore`、`.gitattributes`（改行を LF に統一し、Windows と WSL の混在による差分を防ぐ）、`SPEC.md` を `docs/` へ移動
- 確認：`git status` がきれいな状態でコミットできる

### ステップ2：backend（Laravel + Sail + MySQL 8）

- Laravel 公式の `laravel.build` スクリプトに `with=mysql` を付けて `backend/` を作成する。PHP や Composer を WSL に入れる必要はなく、すべて Docker 内で動く
- Sail は docker compose を Laravel 向けに包んだもの。PHP と MySQL のバージョンを開発環境と CI でそろえられる
- 確認：`sail up -d` → `sail artisan migrate` が成功し、`sail artisan db:show` で MySQL 8 に接続できている

### ステップ3：GET /api/health

- 最近の Laravel は API ルートが初期状態で無効なので、`sail artisan install:api` で `routes/api.php` と Sanctum を有効にする（Sanctum は第2週のログインで使う）
- `HealthController` を作り `{"status":"ok","database":"ok"}` を返す。DB には `SELECT 1` を実行し、失敗したら 503 を返す（「アプリは起動しているが DB に届かない」状態も見分けるため）
- PHPUnit の Feature テストを1本追加する
- 確認：`curl http://localhost/api/health` で JSON が返り、`sail test` が通る

### ステップ4：frontend（Vite + React + TypeScript）

- `npm create vite@latest frontend -- --template react-ts` で作成する
- `vite.config.ts` に、`/api` と `/sanctum` を `http://localhost`（Sail）へ転送するプロキシを設定する（ブラウザからは同じオリジンに見えるため、CORS とクッキーの問題を避けられる）
- `App.tsx` で `/api/health` を取得して表示する。今週は `fetch` だけで済ませ、TanStack Query は第3週から入れる
- `package.json` に `typecheck` スクリプト（`tsc -b`）を追加する
- 確認：`npm run dev` で http://localhost:5173 に status が表示され、`npm run typecheck` がエラーなしで終わる

### ステップ5：GitHub Actions

- `.github/workflows/ci.yml` に次の2つのジョブを作る
  - frontend：`npm ci` → `npm run typecheck`
  - backend：MySQL 8 のサービスコンテナを起動 → `composer install` → `php artisan test`
- `phpunit.xml` を初期設定の SQLite から MySQL の `testing` データベースに変える（本番と同じ MySQL で JSON 列などの挙動を確かめるため）
- 確認：push 後に Actions の画面で2つのジョブが緑になる

### ステップ6：仕上げ

- CLAUDE.md の「よく使うコマンド」に Sail や npm のコマンドを追記し、第1週にチェックを入れる
- 確認：記載したコマンドをそのまま実行して動く

## 補足

- コミットはステップごとに1つずつ作る
- Vitest の CI 組み込みは今回の範囲外。テストを書き始める週に追加する
