--TEST--
stdlib grapheme_levenshtein() — phantom on PHP_COMPILER_PROFILE=8.4 (Zend 8.4 never ships; #22661 / #27591)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('grapheme_levenshtein') ? "fail\n" : "ok\n";
echo is_callable('grapheme_levenshtein') ? "fail\n" : "ok\n";
--EXPECT--
ok
ok
