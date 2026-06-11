--TEST--
stdlib mb_trim() — backed enum case TypeError (#5957, php-src-strict)
--FILE--
<?php
enum E: string
{
    case A = 'x';
}
try {
    mb_trim(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_trim(): Argument #1 ($string) must be of type string, E given
