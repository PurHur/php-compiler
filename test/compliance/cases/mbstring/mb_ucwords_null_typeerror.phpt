--TEST--
mbstring mb_ucwords() null $string — TypeError on 8.4 profile (#19433, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    mb_ucwords(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo mb_ucwords('hello'), "\n";
--EXPECT--
TypeError: mb_ucwords(): Argument #1 ($string) must be of type string, null given
Hello
