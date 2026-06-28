--TEST--
stdlib mb_convert_kana() — kana width conversion (ext/mbstring/mbstring.c, #13099)
--FILE--
<?php
echo function_exists('mb_convert_kana') ? 'yes' : 'no', "\n";
echo mb_convert_kana('テスト', 'KV'), "\n";
echo mb_convert_kana('ﾃｽﾄ', 'KV'), "\n";
echo mb_convert_kana('ｱｲｳ', 'k'), "\n";
echo mb_convert_kana('カタカナ', 'c'), "\n";
try {
    mb_convert_kana('test', 'Z');
    echo "no-error\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
yes
テスト
テスト
ｱｲｳ
かたかな
mb_convert_kana(): Argument #2 ($mode) contains invalid flag: 'Z'
