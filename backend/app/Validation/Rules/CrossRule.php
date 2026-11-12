<?php

namespace App\Validation\Rules;

use App\Validation\Issue;
use App\Validation\MergedMasters;

/**
 * 横断検証のルール。他の行や他のマスタを見ないと判定できないものを、1ルール1クラスで書く。
 * ルールを足すときは、このインターフェースを実装したクラスを作り、CrossValidator::rules() に並べる。
 *
 * 型が合わない値（数値の列に文字など）は単一セルの検証でエラーになるため、ここでは判定せずに飛ばす。
 */
interface CrossRule
{
    /**
     * @return iterable<Issue>
     */
    public function check(MergedMasters $masters): iterable;
}
