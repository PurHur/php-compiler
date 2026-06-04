--TEST--
stdlib array_fill_keys() — enum case keys list must Error (ext/standard/array.c #5600)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
try {
    $r = array_fill_keys([E::A, E::B], 'x');
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Object of class E could not be converted to string
