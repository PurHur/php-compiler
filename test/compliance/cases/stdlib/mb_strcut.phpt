--TEST--
stdlib mb_strcut() byte-safe UTF-8 cut (VM, #4573, ext/mbstring/mbstring.c)
--FILE--
<?php
echo (int) function_exists('mb_strcut'), "\n";
$s = '日本語テスト';
echo mb_strcut($s, 0, 3, 'UTF-8'), "\n";
echo mb_strcut($s, 0, 6, 'UTF-8'), "\n";
echo mb_strcut($s, 3, null, 'UTF-8'), "\n";
echo mb_strcut($s, -3, null, 'UTF-8'), "\n";
echo mb_strcut($s, 0, -3, 'UTF-8'), "\n";
echo mb_strcut($s, 100, 2, 'UTF-8'), "\n";
echo mb_strcut($s, 0, 0, 'UTF-8'), "\n";
echo mb_strcut('hello world', 0, 5, 'UTF-8'), "\n";
echo mb_strcut('hello world', 6, 5, 'UTF-8'), "\n";
--EXPECT--
1
日
日本
本語テスト
ト
日本語テス

hello
world
