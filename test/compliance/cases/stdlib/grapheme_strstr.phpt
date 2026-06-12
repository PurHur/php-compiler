--TEST--
stdlib grapheme_strstr()/grapheme_stristr() — grapheme cluster search (#7221, ext/intl/grapheme)
--FILE--
<?php
echo (int) function_exists('grapheme_strstr'), "\n";
echo (int) function_exists('grapheme_stristr'), "\n";

$haystack = "a\xCC\x81bc";
echo grapheme_strstr($haystack, 'b'), "\n";
echo grapheme_stristr("Äbc", 'ä'), "\n";
var_export(grapheme_strstr('hello', 'z'));
echo "\n";
var_export(grapheme_strstr('hello', ''));
echo "\n";
echo grapheme_strstr('hello', 'ell', true), "\n";
--EXPECT--
1
1
bc
Äbc
false
'hello'
h