--TEST--
stdlib grapheme_strripos() — case-insensitive reverse grapheme search (#20810, ext/intl/grapheme)
--FILE--
<?php
echo (int) function_exists('grapheme_strripos'), "\n";
echo (int) function_exists('grapheme_strchr'), "\n";
echo (int) function_exists('grapheme_strrchr'), "\n";

$s = "äbcÄbÄ";
var_export(grapheme_strripos($s, 'Ä'));
echo "\n";
var_export(grapheme_strripos($s, 'ä'));
echo "\n";
var_export(grapheme_strripos('hello', 'L'));
echo "\n";
var_export(grapheme_strripos('hello', 'z'));
echo "\n";
var_export(grapheme_strripos('hello', ''));
echo "\n";
echo grapheme_strripos('abAba', 'A', 1), "\n";

$emoji = "ab😊cd😊x";
var_export(grapheme_strripos($emoji, '😊'));
echo "\n";
--EXPECT--
1
0
0
5
5
3
false
5
4
5
