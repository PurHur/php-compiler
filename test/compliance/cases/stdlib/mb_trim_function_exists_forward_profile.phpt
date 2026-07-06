--TEST--
stdlib mb_trim/ltrim/rtrim — function_exists on PHP_COMPILER_PROFILE=8.4 (#16998, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
    echo $fn, '=', function_exists($fn) ? '1' : '0', ' ';
    echo is_callable($fn) ? '1' : '0', "\n";
}
$s = "\u{3000}hi\u{3000}";
echo mb_trim($s), "\n";
--EXPECT--
mb_trim=1 1
mb_ltrim=1 1
mb_rtrim=1 1
hi
