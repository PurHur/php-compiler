--TEST--
stdlib grapheme_strripos() JIT — compile-time fold (#20810)
--FILE--
<?php
echo (int) function_exists('grapheme_strripos'), "\n";
$s = "äbcÄbÄ";
var_export(grapheme_strripos($s, 'Ä'));
echo "\n";
var_export(grapheme_strripos('hello', 'L'));
echo "\n";
--EXPECT--
1
5
3
