--TEST--
stdlib mb_str_pad() — backed enum case TypeError on PROFILE=8.4 (#6081, #22373, php-src-strict)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
enum E: string { case X = 'hi'; }
try {
    mb_str_pad(E::X, 5);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_str_pad(): Argument #1 ($string) must be of type string, E given
