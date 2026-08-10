--TEST--
stdlib grapheme_stripos()/grapheme_strrpos() — grapheme cluster search (#6153, ext/intl/grapheme)
--FILE--
<?php
echo (int) function_exists('grapheme_stripos'), "\n";
echo (int) function_exists('grapheme_strrpos'), "\n";

$s = "äbcÄ";
var_export(grapheme_stripos($s, 'Ä'));
echo "\n";
var_export(grapheme_strrpos($s, 'b'));
echo "\n";
var_export(grapheme_stripos('hello', 'z'));
echo "\n";
var_export(grapheme_strrpos('hello', 'l'));
echo "\n";
var_export(grapheme_stripos('hello', ''));
echo "\n";
var_export(grapheme_stripos('abc', 'b', 2));
echo "\n";
echo grapheme_strrpos('ababa', 'a', 1), "\n";
--EXPECT--
1
1
0
1
false
3
0
false
4
