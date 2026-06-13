--TEST--
AOT: mb_strcut() byte-safe UTF-8 cut (#4573)
--FILE--
<?php
$s = '日本語テスト';
echo mb_strcut($s, 0, 3, 'UTF-8'), "\n";
echo mb_strcut('hello world', 6, 5, 'UTF-8'), "\n";
--EXPECT--
日
world
