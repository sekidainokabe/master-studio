<?php

/*
 * 入力チェック（バリデーション）のエラーメッセージの日本語訳。
 *
 * 翻訳パッケージは入れず、このアプリで使っているルールと、よく使うルールだけを訳している。
 * ここにないルールは、英語（fallback_locale）のメッセージになる。
 * :attribute は項目名（各 FormRequest の attributes() で日本語にしている）に置き換わる。
 */

return [
    'array' => ':attributeは配列で指定してください。',
    'boolean' => ':attributeは「はい」か「いいえ」で指定してください。',
    'date' => ':attributeは日付で指定してください。',
    'distinct' => ':attributeが重複しています。',
    'email' => ':attributeはメールアドレスの形式で入力してください。',
    'enum' => ':attributeは選択肢にない値です。',
    'exists' => '選択した:attributeは存在しません。',
    'in' => ':attributeは選択肢にない値です。',
    'integer' => ':attributeは整数で入力してください。',
    'list' => ':attributeはリスト（配列）で指定してください。',
    'max' => [
        'array' => ':attributeは:max件以下にしてください。',
        'file' => ':attributeは:maxキロバイト以下にしてください。',
        'numeric' => ':attributeは:max以下で入力してください。',
        'string' => ':attributeは:max文字以内で入力してください。',
    ],
    'min' => [
        'array' => ':attributeは:min件以上にしてください。',
        'file' => ':attributeは:minキロバイト以上にしてください。',
        'numeric' => ':attributeは:min以上で入力してください。',
        'string' => ':attributeは:min文字以上で入力してください。',
    ],
    'numeric' => ':attributeは数値で入力してください。',
    'present' => ':attributeを指定してください。',
    'regex' => ':attributeの形式が正しくありません。',
    'required' => ':attributeは必須です。',
    'string' => ':attributeは文字列で入力してください。',
    'unique' => 'その:attributeはすでに使われています。',

    'attributes' => [
        'email' => 'メールアドレス',
        'password' => 'パスワード',
        'status' => '状態',
        'author_id' => '作成者',
    ],
];
