--TEST--
stdlib grapheme_*pos empty needle — UTF-16 offset like Zend (php-src grapheme_string.c; #29495)
--FILE--
<?php
error_reporting(E_ALL);

foreach (['grapheme_strpos', 'grapheme_stripos', 'grapheme_strrpos', 'grapheme_strripos'] as $f) {
    echo "$f=", var_export($f('ab', ''), true), "\n";
}

echo 'strpos_o2=', var_export(grapheme_strpos('hello', '', 2), true), "\n";
echo 'strrpos_o2=', var_export(grapheme_strrpos('hello', '', 2), true), "\n";
echo 'strpos_neg=', var_export(grapheme_strpos('hello', '', -1), true), "\n";
echo 'strrpos_neg=', var_export(grapheme_strrpos('hello', '', -1), true), "\n";

$decomp = "a\xCC\x81b";
echo 'mb_strpos0=', var_export(grapheme_strpos($decomp, ''), true), "\n";
echo 'mb_strpos1=', var_export(grapheme_strpos($decomp, '', 1), true), "\n";
echo 'mb_strrpos=', var_export(grapheme_strrpos($decomp, ''), true), "\n";

try {
    grapheme_strpos('hello', '', 6);
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
grapheme_strpos=0
grapheme_stripos=0
grapheme_strrpos=2
grapheme_strripos=2
strpos_o2=2
strrpos_o2=5
strpos_neg=4
strrpos_neg=4
mb_strpos0=0
mb_strpos1=2
mb_strrpos=3
grapheme_strpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)
