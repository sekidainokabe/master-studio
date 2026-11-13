# デプロイ手順（Railway）

- 公開 URL：https://master-studio-production.up.railway.app/
- 構成：Railway の1プロジェクトに、2つのサービスがある
  - `master-studio`：アプリ。リポジトリ直下の `Dockerfile` からビルドする
  - `MySQL`：DB。データはボリューム `mysql-volume` に保存される
- 常時稼働はさせず、必要なときだけ ON にする（費用を抑えるため）
- GitHub への push で自動デプロイしない設定にしている（Settings → Source → Auto deploys を Disable）

## ON にする（起動）

1. **MySQL** のサービスの「Deployments」で、最新のデプロイの「⋮」→「Redeploy」を選ぶ。**ACTIVE になるまで待つ**
2. **アプリ**のサービスを選んだ状態で `Ctrl + K` →「**Deploy Latest Commit**」を選ぶ
3. `/api/health` が `{"status":"ok","database":"ok"}` を返せば完了

注意：
- アプリでは「Redeploy」を使わない。Redeploy は**選んだデプロイと同じコミット**を再デプロイするため、一覧に残っている古いデプロイから実行すると、修正前のコードが起動してしまう（2026-11-05 に実際に起きた）
- MySQL より先にアプリを起動すると、起動時の `migrate` が DB に接続できずに失敗する

## OFF にする（停止）

1. **アプリ**のサービスで、ACTIVE のデプロイの「⋮」→「Remove deployment」を選ぶ
2. **MySQL** のサービスでも、同じように「Remove deployment」を選ぶ

設定、環境変数、MySQL のボリューム（データ）は残る。止めている間にかかるのは、ボリュームの保存料金だけ。

## コードを更新したとき

main に push したあと、上の「ON にする」の 2 と同じく `Ctrl + K` →「Deploy Latest Commit」を選ぶ。

本番へ出す前に、Git に入っているファイルだけでイメージを作り、起動することを確かめるとよい。空のフォルダなど、手元にしかないものは Railway でのビルドには含まれないため（2026-11-04 にこれで起動に失敗した）。

```bash
cd ~/master-studio && git ls-files -z | tar --null -T - -c | docker build -t master-studio:check -
```

## 起動時の処理

コンテナを起動すると `docker/start.sh` が次の順に実行される。

1. `config:cache` / `route:cache`
2. `migrate --force`
3. `app:seed-if-empty`：ユーザーが1人もいないときだけ、初期データ（ユーザー・マスタ・v1 のリリース）を入れる
4. `app:seed-demo`：デモ用の変更セット（反映済み1つ・エラー入りの下書き1つ）が、まだないときだけ入れる
5. Web サーバー（FrankenPHP）の起動

4 以外のどこかで失敗すると、Web サーバーまで進まない（4 は失敗しても起動を続け、ログに「デモ用データを入れられませんでした」と出る）。その場合は Healthcheck failure になるので、「View logs」→「Deploy Logs」で原因を確かめる。

## 環境変数（アプリのサービス）

| 変数 | 値 |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | `sail artisan key:generate --show` で作った値（リポジトリやチャットには載せない） |
| `APP_URL` | `https://master-studio-production.up.railway.app` |
| `DB_CONNECTION` | `mysql` |
| `DB_URL` | `${{MySQL.MYSQL_URL}}`（MySQL サービスの変数を参照する） |
| `SESSION_SECURE_COOKIE` | `true` |
| `SANCTUM_STATEFUL_DOMAINS` | `master-studio-production.up.railway.app` |
| `LOG_CHANNEL` | `stderr` |
| `PORT` | `8080` |

ほかの設定：
- Networking：公開ドメインの転送先ポートは 8080
- Deploy：Healthcheck Path は `/api/health`

## 料金

- 2026-11-04 から30日間の無料トライアル（$5 分のクレジット）を利用中
- トライアルが終わったら、Free プランか Hobby プラン（月5ドル）に切り替える。Hobby プランでカードを登録するときに、ワークスペースの使用料に上限（Hard limit）を設定する
