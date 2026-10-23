# master-studio 仕様書

## 1. 概要

オリジナル世界観のアクションゲームを想定し、そのマスタデータ（攻撃パターン、敵、ステージ、敵配置）を Web で管理する社内ツール。スプレッドシート運用で起きがちな「型ミス」「参照切れ」「誰がいつ何を変えたか分からない」「反映事故」を解決することがテーマ。

中核は**変更セット方式**。編集者は変更セットを作成してその中でデータを編集し、保存のたびに検証が走る。レビュー依頼後、承認者が差分を確認して承認すると、本番データに反映されてバージョンが上がり、そのバージョンの JSON を出力できる。

さらに、出力した JSON を読み込む簡易ゲームを同梱し、承認前の変更セットのデータでテストプレイできるようにする。

## 2. 設計方針：スキーマ駆動

マスタの定義（列名、型、必須、範囲、列挙値、参照先）をデータベースに持ち、レコードは JSON 列に格納する。マスタの種類が増えても同じ画面・同じ API で扱える汎用ツールにする。

列の型は次の6種類に限定する：`int` / `float` / `string` / `bool` / `enum` / `ref`

スキーマ定義の編集画面は作らず、シードデータで用意する（余裕があれば後で追加）。

## 3. データベース（MySQL 8）

### users

| カラム | 型 | 備考 |
|---|---|---|
| id | BIGINT | 主キー |
| name | VARCHAR(100) | |
| email | VARCHAR(255) | 一意 |
| password | VARCHAR(255) | ハッシュ |
| role | ENUM | editor / approver / admin |

### master_tables

| カラム | 型 | 備考 |
|---|---|---|
| id | BIGINT | 主キー |
| key | VARCHAR(50) | 一意（abilities など） |
| name | VARCHAR(100) | 表示名 |
| description | TEXT | 任意 |

### master_columns

| カラム | 型 | 備考 |
|---|---|---|
| id | BIGINT | 主キー |
| master_table_id | BIGINT | 外部キー |
| key | VARCHAR(50) | (master_table_id, key) で一意 |
| name | VARCHAR(100) | 表示名 |
| type | ENUM | int / float / string / bool / enum / ref |
| is_required | BOOLEAN | |
| min_value / max_value | DECIMAL | 数値範囲（任意） |
| max_length | INT | 文字列上限（任意） |
| enum_values | JSON | 列挙の選択肢 |
| ref_master_table_id | BIGINT | 参照先マスタ（type=ref のとき） |
| sort_order | INT | 表示順 |

### master_records（本番データ）

| カラム | 型 | 備考 |
|---|---|---|
| id | BIGINT | 主キー |
| master_table_id | BIGINT | 外部キー |
| record_key | VARCHAR(50) | (master_table_id, record_key) で一意。参照列はこの値を指す |
| data | JSON | 各列の値 |
| updated_by | BIGINT | 最終更新者 |
| release_id | BIGINT | 最後に反映されたリリース |

### changesets

| カラム | 型 | 備考 |
|---|---|---|
| id | BIGINT | 主キー |
| title | VARCHAR(200) | |
| description | TEXT | 変更の目的 |
| status | ENUM | draft / in_review / rejected / applied |
| author_id / reviewer_id | BIGINT | 作成者・承認者 |
| base_release_id | BIGINT | 作成時点のリリース（競合検出用） |
| review_comment | TEXT | 却下理由など |
| submitted_at / reviewed_at | DATETIME | |

### changeset_items

| カラム | 型 | 備考 |
|---|---|---|
| id | BIGINT | 主キー |
| changeset_id | BIGINT | 外部キー |
| master_table_id | BIGINT | |
| record_key | VARCHAR(50) | (changeset_id, master_table_id, record_key) で一意 |
| operation | ENUM | create / update / delete |
| before_data / after_data | JSON | 差分表示用 |

### releases

version（一意）、changeset_id、released_by、export_path（出力 JSON の保存先）、created_at

### audit_logs

user_id、action、target_type、target_id、detail（JSON）、created_at。(target_type, target_id) にインデックス。

検証エラーは保存せず、編集のたびに計算する。

## 4. サンプルマスタ（オリジナルのアクションゲーム想定）

### abilities（攻撃パターン）

| 列 | 型 | 制約 |
|---|---|---|
| name | string | 必須、30文字以内 |
| attack_type | enum | melee / projectile / area / guard |
| element | enum | none / fire / ice / thunder / wind |
| power | int | 0〜999 |
| range | float | 0〜50 |
| startup_frames | int | 0〜600（60fps 想定） |
| cooldown_frames | int | 0〜600 |

### enemies（敵）

| 列 | 型 | 制約 |
|---|---|---|
| name | string | 必須、30文字以内 |
| rank | enum | normal / mid_boss / boss |
| movement | enum | walk / fly / jump / stationary |
| hp | int | 1〜9999 |
| contact_damage | int | 0〜99 |
| move_speed | float | 0〜20 |
| ability_id | ref → abilities | 任意 |
| score | int | 0〜99999 |

### stages（ステージ）

| 列 | 型 | 制約 |
|---|---|---|
| name | string | 必須、30文字以内 |
| world | int | 1〜9 |
| stage_no | int | 1〜20 |
| width / height | int | ステージの広さ |
| time_limit_sec | int | 0〜999（0は無制限） |
| difficulty | int | 1〜5 |
| unlock_stage_id | ref → stages | 任意（自己参照） |

### stage_enemies（敵配置）

| 列 | 型 | 制約 |
|---|---|---|
| stage_id | ref → stages | 必須 |
| enemy_id | ref → enemies | 必須 |
| pos_x / pos_y | float | ステージの width / height の範囲内 |
| spawn_frame | int | 0以上 |
| respawn | bool | |

### シードデータ

攻撃パターン20件、敵30件、ステージ12件（3ワールド×4）、敵配置150件程度。名称はすべてオリジナル。

**デモ用変更セット**を1つ用意し、次のエラーをわざと含める：HP が 0 の敵、ステージ外にはみ出した敵配置、ボスが2体いるステージ、削除済みの攻撃パターンを参照している敵。

## 5. 検証ルール

**単一セル（フロントで即時 ＋ サーバーで最終判定）**：型、必須、数値範囲、文字数、列挙値

**横断（サーバーの validation API）**

- 参照先の存在チェック（同じ変更セット内で新規作成されたレコードも参照先として認める）
- 参照されているレコードの削除禁止
- record_key の一意性
- 敵配置の座標がステージの width / height の範囲内
- 1ステージにボス（rank=boss）は1体まで
- unlock_stage_id が自分自身でないこと、解放条件が循環していないこと

## 6. 画面

| パス | 画面 | 内容 |
|---|---|---|
| /login | ログイン | |
| /masters | マスタ一覧 | 件数と最終更新日時 |
| /masters/:key | 本番データ閲覧 | 読み取り専用グリッド、検索・絞り込み、変更セット作成への導線 |
| /changesets | 変更セット一覧 | 状態・作成者で絞り込み |
| /changesets/:id/edit | 編集 | マスタごとのタブ、スプレッドシート風グリッド、検証結果パネル、CSV 取り込み、レビュー依頼 |
| /changesets/:id | 変更セット詳細 | 差分（追加=緑、削除=赤、変更セル=黄）、検証サマリ、コメント、承認・却下、テストプレイ |
| /releases | リリース履歴 | バージョンごとの内容、JSON ダウンロード |
| /play | ゲーム | 本番データまたは変更セットのデータでプレイ |

エラーのあるセルは赤くし、ホバーで理由を表示する。参照列はプルダウンで選択する。

## 7. API

| メソッド | パス | 内容 |
|---|---|---|
| POST | /api/login, /api/logout | 認証 |
| GET | /api/me | ログイン中ユーザーと権限 |
| GET | /api/masters | マスタ一覧と件数 |
| GET | /api/masters/{key}/schema | 列定義 |
| GET | /api/masters/{key}/records | 本番データ |
| GET / POST | /api/changesets | 一覧・作成 |
| GET / PATCH / DELETE | /api/changesets/{id} | 詳細・編集・削除（下書きのみ） |
| GET | /api/changesets/{id}/masters/{key}/records | 本番に変更を重ねた編集用データ |
| PUT | /api/changesets/{id}/masters/{key}/records | 複数行の追加・更新・削除を一括保存 |
| DELETE | /api/changesets/{id}/items/{itemId} | 個別の変更を取り消し |
| POST | /api/changesets/{id}/masters/{key}/import | CSV 取り込み |
| GET | /api/changesets/{id}/validation | 横断検証の結果 |
| GET | /api/changesets/{id}/diff | 差分 |
| POST | /api/changesets/{id}/submit | レビュー依頼 |
| POST | /api/changesets/{id}/approve | 承認して本番反映 |
| POST | /api/changesets/{id}/reject | コメント付きで却下 |
| POST | /api/changesets/{id}/reopen | 却下を下書きに戻す |
| GET | /api/releases | リリース履歴 |
| GET | /api/releases/{version}/export | JSON 出力 |

## 8. 権限

- editor：変更セットの作成、自分の変更セットの編集・レビュー依頼
- approver：承認・却下（**自分が作成した変更セットは承認できない**）
- admin：すべて

## 9. 承認処理

1つのトランザクションで次を行い、途中で失敗したらすべて巻き戻す。

1. 対象レコードをロック
2. base_release_id 以降に対象レコードが変更されていないか確認（競合検出）
3. サーバー側で再検証
4. 本番データへ反映
5. リリース作成と JSON 出力
6. 操作履歴の記録

## 10. 簡易ゲーム

- TypeScript + Canvas、ゲームエンジンは使わない。React アプリ内の1画面
- 見た目は図形のみ。プレイヤーは左右移動・ジャンプ・攻撃のみ
- 敵は movement に応じた単純な動き、ability に応じた弾や攻撃
- ステージの広さ、敵の配置・HP・速度などはすべてマスタから読み込む
- 本番データ（最新リリース）と、承認前の変更セットのデータの両方でプレイできる
- 作り込みすぎない。見た目より「動きが破綻しないこと」を優先

## 11. デモシナリオ（3分）

1. 編集者でログインし、デモ用変更セットを開く。赤いエラーセルと検証結果を見せる
2. エラーを修正し、敵の HP と配置を変更する
3. テストプレイで変更を確認し、レビュー依頼
4. 承認者でログインし、差分を確認して承認
5. 本番データのゲームでも変更が反映されていることを見せる

## 12. 対象外（余裕があれば）

- スキーマ定義の編集画面
- 過去バージョンへのロールバック
- 延滞・通知などのメール連携
