# master-studio

ゲームのマスタデータを「変更セット（プルリクエストのような単位）」で編集・検証・承認・出力する社内Webサービス。転職用ポートフォリオとして作成している。詳細仕様は @docs/SPEC.md を参照。

## このプロジェクトの目的

- HAL研究所「コーポレートウェブシステムエンジニア」への応募用ポートフォリオ（応募目標：2026年12月20日）
- 必須要件：HTML / CSS / TypeScript / React / PHP / SQL、フロントエンドとサーバーサイド両方の開発
- 歓迎要件：データベース設計、利用者100名以上のサービスの設計・実装
- 評価の中心はマスタ管理ツールの設計と実装の質。簡易ゲームはデータ連動を示す「おまけ」

## 開発者について

- ECモール運営代行会社の社内SE。Python / GAS / JavaScript / HTML / CSS / スプレッドシート / CSV / Supabase に精通
- TypeScript / React / PHP / Laravel / SQL（手書き）は学習しながら進めている
- 面接で自分の言葉で説明できる必要があるため、新しく使う概念・設計判断は短く理由を添えて説明すること

## 作業ルール（最重要）

- **コードやファイルを作成・変更する前に、必ず計画（変更するファイル、内容、理由）を提示し、承認を得てから実装する**
- 承認された範囲外の変更はしない。範囲外の問題に気づいたら報告だけする
- 1回の作業は小さく区切る。終わったら動作確認方法を示す
- 説明は簡潔に、文章で。前置きや同じ説明の繰り返しは不要
- 任天堂・HAL研究所などの実在IP（キャラクター名、能力名、画像、ロゴ）は一切使わない。世界観・名称はすべてオリジナル
- コミットは意味のある単位で、メッセージは日本語でよい

## 環境

- Windows + WSL2（Ubuntu）。プロジェクトは WSL 側のファイルシステム（~/ 配下）に置く。/mnt/c 配下では作業しない
- Docker Desktop（WSL2 統合を有効化）
- Claude Code は WSL 内で実行する

## 技術構成

- モノレポ
  - `backend/`：Laravel（Laravel Sail で起動）、PHP、MySQL 8、Laravel Sanctum（SPA クッキー認証）
  - `frontend/`：Vite + React + TypeScript、React Router、TanStack Query
  - `docs/`：仕様書
- 開発時は Vite のプロキシで `/api` と `/sanctum` を Laravel に転送する（CORS・クッキー問題の回避）
- テスト：PHPUnit（backend）、Vitest（frontend）
- CI：GitHub Actions（frontend の型チェック・テスト、backend の PHPUnit を MySQL サービス付きで実行）

## よく使うコマンド

すべて WSL（Ubuntu）のターミナルで実行する。

### backend（`~/master-studio/backend`）

```bash
./vendor/bin/sail up -d --wait       # コンテナ起動（MySQL が healthy になるまで待つ）
./vendor/bin/sail stop               # コンテナ停止
./vendor/bin/sail artisan migrate    # マイグレーション
./vendor/bin/sail artisan migrate:fresh --seed   # DB を作り直してシード（開発データは消える）
./vendor/bin/sail artisan app:seed-if-empty      # 未シードのときだけシード（本番の起動時に実行される）
./vendor/bin/sail artisan db:show    # DB 接続確認
./vendor/bin/sail test               # PHPUnit（MySQL の testing DB を使う）
curl http://localhost/api/health     # API の疎通確認
```

### frontend（`~/master-studio/frontend`）

```bash
npm run dev          # 開発サーバー（http://localhost:5173、/api と /sanctum は Sail へプロキシ）
npm run typecheck    # 型チェック
npm test             # Vitest
npm run lint         # oxlint
```

パッケージを追加したら開発サーバーを起動し直す（古い依存ファイルが残り「Invalid hook call」などが出るため）。

### 本番用イメージ（`~/master-studio`）

```bash
docker build -t master-studio:local .   # リポジトリ直下の Dockerfile でビルド
```

ローカルで起動するときは Sail のネットワーク（`backend_sail`）につなぎ、`APP_KEY`・`DB_*`・`SANCTUM_STATEFUL_DOMAINS=localhost:8080` を渡して `-p 8080:8080` で起動する。起動時にマイグレーションと `app:seed-if-empty` が実行される。

### ポート

- 80：Laravel（Sail）／3306：MySQL／5173：frontend の Vite
- Sail 側の Vite は `backend/.env` の `VITE_PORT=5174` で 5173 との衝突を避けている

## 現在の進捗

- [x] 第1週（10/26〜11/1）：環境構築、/api/health の疎通、CI 雛形
- [ ] 第2週（11/2〜11/8）：マイグレーション、シード、ログイン、初回デプロイ
  - デプロイのみ保留（Railway の新規登録が停止中のため。10/26 時点）。本番用イメージはローカルで動作確認済み。Railway での残り作業は docs/plan-week2.md の 5-3・5-4
- [ ] 第3週（11/9〜11/15）：スキーマ・本番データ API、マスタ一覧・閲覧、変更セット作成・一覧
- [ ] 第4週（11/16〜11/22）：編集画面、一括保存、フロント側単一セル検証
- [ ] 第5週（11/23〜11/29）：サーバー側横断検証、CSV 取り込み、差分
- [ ] 第6週（11/30〜12/6）：承認フロー、リリース、JSON 出力、権限、操作履歴、テスト、デモ用シード
- [ ] 第7週（12/7〜12/13）：簡易ゲーム、テストプレイ
- [ ] 第8週（12/14〜12/20）：README、デモ GIF、応募

6週目終了時にツールが未完成なら、ゲームは見送ってツールの仕上げを優先する。
