--TEST--
stdlib mb_str_pad() — backed enum case TypeError (#6081, php-src-strict)
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
