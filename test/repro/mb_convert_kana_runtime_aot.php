<?php
// AOT NestedJIT mb_convert_kana runtime (#34294)
$s = 'ｱｲｳ';
echo mb_convert_kana($s, 'KV'), "\n";
$t = 'ｶﾞ';
echo mb_convert_kana($t), "\n";
$u = 'アイウ';
echo mb_convert_kana($u, 'k'), "\n";
