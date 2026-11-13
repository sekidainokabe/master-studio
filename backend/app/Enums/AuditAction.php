<?php

namespace App\Enums;

/**
 * 操作履歴（audit_logs.action）に残す操作の種類。画面の絞り込みと表示名にも使う
 */
enum AuditAction: string
{
    case Login = 'login';
    case ChangesetCreated = 'changeset.create';
    case ChangesetUpdated = 'changeset.update';
    case ChangesetDeleted = 'changeset.delete';
    case RecordsSaved = 'records.save';
    case ItemReverted = 'records.revert';
    case CsvImported = 'records.import_csv';
    case Submitted = 'changeset.submit';
    case Approved = 'changeset.approve';
    case Rejected = 'changeset.reject';
    case Reopened = 'changeset.reopen';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'ログイン',
            self::ChangesetCreated => '変更セットの作成',
            self::ChangesetUpdated => '変更セットの編集',
            self::ChangesetDeleted => '変更セットの削除',
            self::RecordsSaved => 'データの保存',
            self::ItemReverted => '変更の取り消し',
            self::CsvImported => 'CSV の取り込み',
            self::Submitted => 'レビュー依頼',
            self::Approved => '承認',
            self::Rejected => '却下',
            self::Reopened => '下書きに戻す',
        };
    }
}
