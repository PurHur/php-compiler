--TEST--
stdlib grapheme_levenshtein() — not advertised on default ≤8.4 profile (#22661 / #27591)
--FILE--
<?php
echo function_exists('grapheme_levenshtein') ? "fail\n" : "ok\n";
echo is_callable('grapheme_levenshtein') ? "fail\n" : "ok\n";
--EXPECT--
ok
ok
