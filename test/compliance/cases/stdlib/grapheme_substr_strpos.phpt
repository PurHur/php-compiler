--TEST--
stdlib grapheme_substr()/grapheme_strpos() — grapheme cluster slice/search (#3352, ext/intl/grapheme)
--FILE--
<?php
echo (int) function_exists('grapheme_substr'), "\n";
echo (int) function_exists('grapheme_strpos'), "\n";

$s = "a\xCC\x81b";
echo grapheme_strlen($s), "\n";
echo grapheme_substr($s, 0, 1), "\n";
echo grapheme_substr($s, 1), "\n";
var_export(grapheme_strpos($s, 'b'));
echo "\n";
var_export(grapheme_strpos($s, 'z'));
echo "\n";
var_export(grapheme_strpos('hello', ''));
echo "\n";
echo grapheme_substr('abc', -1), "\n";
echo grapheme_substr('abcdef', 2, 2), "\n";
--EXPECT--
1
1
2
á
b
1
false
0
c
cd
