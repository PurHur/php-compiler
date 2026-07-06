--TEST--
stdlib iconv() — enum case $string operand TypeError (#8851, ext/iconv/iconv.c)
--FILE--
<?php
declare(strict_types=1);
enum E: string { case A = 'x'; }
try {
    iconv('UTF-8', 'UTF-8', E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
iconv(): Argument #3 ($string) must be of type string, E given
