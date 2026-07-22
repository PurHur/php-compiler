--TEST--
stdlib grapheme_str_split() — withheld on PHP 8.2 profile (#22340, ext/intl/grapheme)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo function_exists('grapheme_str_split') ? "fail\n" : "ok\n";
echo is_callable('grapheme_str_split') ? "fail\n" : "ok\n";
--EXPECT--
ok
ok
