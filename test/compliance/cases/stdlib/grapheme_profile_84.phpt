--TEST--
stdlib PHP 8.4 profile — grapheme_str_contains()/grapheme_strimwidth() registered (#16667, ext/intl/grapheme)
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
?>
--EXPECT--
false
true
false
hello
