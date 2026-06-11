--TEST--
stdlib bcceil()/bcfloor() — PHP 8.4 rounding (ext/bcmath/bcmath.c, #6026)
--FILE--
<?php
echo bcceil('1.234'), "\n";
echo bcfloor('1.234'), "\n";
echo bcceil('-1.234'), "\n";
echo bcfloor('-1.9'), "\n";
enum E: string { case X = '1.5'; }
try {
    bcceil(E::X);
    echo "enum uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    bcfloor(E::X);
    echo "enum uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
2
1
-1
-2
bcceil(): Argument #1 ($num) must be of type string, E given
bcfloor(): Argument #1 ($num) must be of type string, E given
