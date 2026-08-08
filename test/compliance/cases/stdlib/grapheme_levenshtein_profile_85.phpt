--TEST--
stdlib grapheme_levenshtein() — PROFILE=8.5 + host intl (#27591, php-src php_intl.stub.php)
--SKIPIF--
<?php
if (!extension_loaded('intl')) die('skip host php-intl required');
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
echo (int) function_exists('grapheme_levenshtein'), "\n";
$nfc = "caf\u{00E9}";
$nfd = "caf\u{0065}\u{0301}";
echo grapheme_levenshtein($nfc, $nfd), "\n";
echo grapheme_levenshtein('kitten', 'sitting'), "\n";
echo grapheme_levenshtein('café', 'cafe'), "\n";
echo grapheme_levenshtein('ab', 'a', 2, 3, 4), "\n";
--EXPECT--
1
0
3
1
4
