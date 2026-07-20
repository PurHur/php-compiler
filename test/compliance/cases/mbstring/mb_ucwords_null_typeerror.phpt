--TEST--
mbstring mb_ucwords() null $string — TypeError on 8.4 profile (#21394, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    mb_ucwords(null);
    echo "NO_THROW\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_ucwords(): Argument #1 ($string) must be of type string, null given
