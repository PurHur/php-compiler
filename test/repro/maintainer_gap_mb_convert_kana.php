<?php

declare(strict_types=1);

$ok = function_exists('mb_convert_kana')
    && mb_convert_kana('テスト', 'KV') === 'テスト'
    && mb_convert_kana('ﾃｽﾄ', 'KV') === 'テスト'
    && mb_convert_kana('ｱｲｳ', 'k') === 'ｱｲｳ'
    && mb_convert_kana('カタカナ', 'c') === 'かたかな';

echo $ok ? "ok\n" : "fail\n";
