--TEST--
stdlib grapheme_levenshtein() — not advertised on any profile (Zend never ships; #22661 / #6998)
--FILE--
<?php
echo function_exists('grapheme_levenshtein') ? "fail\n" : "ok\n";
echo is_callable('grapheme_levenshtein') ? "fail\n" : "ok\n";
--EXPECT--
ok
ok
