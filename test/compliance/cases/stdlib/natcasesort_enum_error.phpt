--TEST--
stdlib natcasesort() — enum case values throw Error (#5607, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$a = [E::B, E::A];
try {
    natcasesort($a);
    echo "uncaught\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Object of class E could not be converted to string
