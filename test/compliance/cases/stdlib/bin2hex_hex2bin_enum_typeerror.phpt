--TEST--
stdlib bin2hex()/hex2bin() — backed enum case TypeError (#5734, ext/standard/string.c)
--FILE--
<?php
enum B: int { case One = 1; }
try {
    bin2hex(B::One);
    echo "bin2hex uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    hex2bin(B::One);
    echo "hex2bin uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
bin2hex(): Argument #1 ($string) must be of type string, B given
hex2bin(): Argument #1 ($string) must be of type string, B given
