# 第2週 作業計画（マイグレーション、シード、ログイン、初回デプロイ）※承認済み（2026-10-23）

## 作業手順

### ステップ1：マイグレーションとモデル

- SPEC の8テーブルを作る（users に role を追加し、ほかの7テーブルは新規）。一意制約と外部キーは SPEC のとおり
- `changesets.base_release_id` と `releases.changeset_id` はお互いを参照し合うため、先に2つとも作り、あとから外部キーだけを追加するマイグレーションに分ける
- role、status、列の型（int や ref など）は PHP の Enum で表し、モデルの `casts` で自動変換する（打ち間違いをコードの段階で防ぐため）。JSON 列は配列に変換する
- 確認：`sail artisan migrate:fresh` が成功し、`SHOW CREATE TABLE` で制約が作られている

### ステップ2：シード

- ユーザー：editor、approver、admin の3人
- 4つのマスタの列定義（SPEC 4章のとおり）
- サンプルデータ：攻撃パターン20件、敵30件、ステージ12件、敵配置150件。名前はすべてオリジナル
- 敵配置は乱数の種（シード値）を固定して生成し、何回実行しても同じデータにする。座標はステージの範囲内、ボスは1ステージに1体まで
- デモ用のエラー入り変更セットは第6週に作る
- PHPUnit で、シードデータの整合性（参照先の存在、座標の範囲、ボスは1体まで）を確かめるテストを書く
- 確認：`migrate:fresh --seed` を実行し、件数とテストを確かめる

### ステップ3：ログイン API（Sanctum の SPA 認証）

- `bootstrap/app.php` で `statefulApi()` を有効にし、`SANCTUM_STATEFUL_DOMAINS` に `localhost:5173` を設定、`APP_URL` を直す
- `POST /api/login`、`POST /api/logout`、`GET /api/me` を作る。ログイン成功時にセッション ID を作り直す（セッション固定攻撃への対策）。ログインに回数制限を付ける（パスワード総当たりへの対策）
- PHPUnit：ログイン成功、パスワード違い、ログアウト、未ログイン時の `/api/me` が 401
- 確認：`sail test`、curl でクッキーを保存しながら一連の流れを試す

### ステップ4：ログイン画面（frontend）

- React Router と TanStack Query を入れる
- API 呼び出しを小さな `fetch` の関数にまとめる。ログイン前に `/sanctum/csrf-cookie` を呼んでトークンをクッキーに受け取り、`X-XSRF-TOKEN` ヘッダーに載せて送る（CSRF 対策の仕組みを説明できるよう axios は使わない）
- `/login` 画面を作る。未ログインで他のページを開いたら `/login` へ移動させ、ヘッダーにユーザー名とログアウトボタンを表示する
- Vitest を導入し、API 呼び出しの関数のテストを書く。CI に frontend のテストを追加する
- 確認：ブラウザでログイン、再読み込み（ログインが保たれる）、ログアウトを試す

### ステップ5：初回デプロイ

- 本番ではビルドした React を Laravel から配信する（画面と API が同じドメインになり、クッキー認証がそのまま動く）
- 本番用 Dockerfile、環境変数、HTTPS、デモ用ユーザーのシードを用意する
- デプロイ先はステップ5の前に決める（第一候補は Railway。最新の料金を確認してから決める）。アカウント作成と支払い方法の登録は開発者が行う
- 確認：公開 URL でログインできる

#### ステップ5の詳細（2026-10-26 承認）

デプロイ先は **Railway**。常時稼働はせず、必要なときだけ ON にする。

- OFF：サービスの「Remove deployment」でコンテナを止める（設定・環境変数・MySQL のボリュームは残る。ボリュームの保存料金だけかかる）
- ON：「Redeploy」で MySQL → アプリの順に起動する
- push のたびに勝手に ON にならないよう、GitHub の自動デプロイは無効にし、画面の「Deploy Latest Commit」でデプロイする
- 最初は30日間の無料枠で試し、MySQL 8.4 が 0.5GB で動けば Free プラン、動かなければ Hobby プラン（月5ドル）にする
- 止めている間の見せ方は、第8週の README のデモ GIF で補う

**5-1：本番用 Dockerfile（リポジトリ直下）**
- マルチステージビルド：Node で frontend をビルド → Composer で本番用パッケージだけを入れる → 実行用イメージ
- 実行環境は FrankenPHP（PHP と Web サーバー Caddy が一体。nginx + php-fpm より構成が単純）
- 確認：ローカルで `docker build` / `docker run` し、動くことを確かめる

**5-2：本番向けの Laravel 側の対応**
- `/api` 以外のパスは SPA の `index.html` を返す
- Railway の前段プロキシを信頼する（TrustProxies）。HTTPS の判定、クッキーの Secure 属性、URL 生成のため
- 「ユーザーが1人もいなければシードする」Artisan コマンドを作り、デプロイのたびに `migrate --force` と一緒に実行する
- 確認：PHPUnit のテストを追加し、`sail test` が通る

**5-3：Railway の設定（開発者の作業が中心）**
- GitHub でアカウント作成、リポジトリへのアクセス許可
- プロジェクト作成、MySQL をテンプレートから追加、GitHub リポジトリをサービスとして追加
- 環境変数（`APP_ENV=production`、`APP_DEBUG=false`、MySQL を参照する `DB_URL`、`SESSION_SECURE_COOKIE=true`、`SANCTUM_STATEFUL_DOMAINS` など）。`APP_KEY` は開発者が `sail artisan key:generate --show` で作り、Railway に直接貼る
- 公開ドメインの発行、自動デプロイの無効化、使用料の上限設定

**5-4：初回デプロイと確認**
- 公開 URL でログイン → 再読み込み → ログアウト
- `APP_DEBUG=false` でエラーの詳細が出ないこと
- OFF → ON を1回試し、データが残っていること
- ON / OFF の手順を `docs/deploy.md` にまとめる

#### 進捗（2026-10-26）

- 5-1・5-2 は完了（本番用イメージをローカルで動作確認済み）
- 5-3 で Railway のアカウントを作ろうとしたところ、「Sign ups are currently disabled」となった。公式ステータスページに 10/23 付けで「新規登録を一時停止」の告知あり
- 5-3・5-4 は再開まで保留し、第3週の作業を先に進める。11/2 ごろまでに再開しなければ、デプロイ先を見直す（候補：Laravel Cloud、Fly.io）

### ステップ6：仕上げ

- CLAUDE.md の進捗を更新する

## 補足

- コミットはステップごとに作る
- 本番のデモ用アカウントは誰でもデータを変えられるため、データを初期状態に戻すコマンドを第6週に用意する
