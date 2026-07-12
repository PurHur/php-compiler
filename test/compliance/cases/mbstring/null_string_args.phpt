--TEST--
mbstring null $string operands — TypeError on 8.2 profile (#18243, ext/mbstring/mbstring.c)
--FILE--
<?php
foreach (['mb_strlen', 'mb_substr', 'mb_strtolower'] as $fn) {
    try {
        if ('mb_substr' === $fn) {
            $fn(null, 0);
        } else {
            $fn(null);
        }
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
mb_strlen: mb_strlen(): Argument #1 ($string) must be of type string, null given
mb_substr: mb_substr(): Argument #1 ($string) must be of type string, null given
mb_strtolower: mb_strtolower(): Argument #1 ($string) must be of type string, null given
