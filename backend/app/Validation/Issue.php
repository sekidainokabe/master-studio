<?php

namespace App\Validation;

/**
 * 横断検証のエラー1件。column が null のものは、行全体のエラー（削除できない行など）
 */
final readonly class Issue
{
    public function __construct(
        public string $master,
        public string $recordKey,
        public ?string $column,
        public string $message,
    ) {}
}
