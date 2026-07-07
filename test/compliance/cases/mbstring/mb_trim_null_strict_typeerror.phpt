--TEST--
mbstring mb_trim/ltrim/rtrim() null $string — TypeError on 8.4 profile (#17132, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
    try {
        $fn(null);
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
mb_trim: mb_trim(): Argument #1 ($string) must be of type string, null given
mb_ltrim: mb_ltrim(): Argument #1 ($string) must be of type string, null given
mb_rtrim: mb_rtrim(): Argument #1 ($string) must be of type string, null given
