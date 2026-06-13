--TEST--
stdlib grapheme_stripos()/grapheme_strrpos() JIT — compile-time fold (#6153)
--FILE--
<?php
echo (int) function_exists('grapheme_stripos'), "\n";
echo (int) function_exists('grapheme_strrpos'), "\n";

$s = "äbcÄ";
var_export(grapheme_stripos($s, 'Ä'));
echo "\n";
var_export(grapheme_strrpos($s, 'b'));
echo "\n";
--EXPECT--
1
1
0
1
