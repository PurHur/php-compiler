--TEST--
stdlib PHP 8.4 profile — grapheme forward-profile helpers callable but not advertised without intl (#11803, ext/intl/grapheme)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(function_exists('grapheme_str_contains'));
echo "\n";
var_export(grapheme_str_contains('hello', 'ell'));
echo "\n";
var_export(function_exists('grapheme_strimwidth'));
echo "\n";
echo grapheme_strimwidth('hello', 0, 10), "\n";
var_export(function_exists('grapheme_strlen'));
echo "\n";
$s = "a\xCC\x81b";
echo grapheme_strlen($s), "\n";
var_export(function_exists('grapheme_substr'));
echo "\n";
echo grapheme_substr($s, 1), "\n";
var_export(function_exists('grapheme_strpos'));
echo "\n";
var_export(grapheme_strpos($s, 'b'));
echo "\n";
var_export(function_exists('grapheme_extract'));
echo "\n";
echo grapheme_extract($s, 1), "\n";
var_export(function_exists('grapheme_str_split'));
echo "\n";
echo count(grapheme_str_split($s)), "\n";
?>
--EXPECT--
false
true
false
hello
false
2
false
b
false
1
false
á
false
2
