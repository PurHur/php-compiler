--TEST--
mbstring mb_str_pad() null $string — TypeError on 8.4 profile (#19184, #22373, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    mb_str_pad(null, 5);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_str_pad(): Argument #1 ($string) must be of type string, null given
