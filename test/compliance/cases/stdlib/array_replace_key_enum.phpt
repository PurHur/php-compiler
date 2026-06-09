--TEST--
stdlib array_replace_key() — enum case keys throw TypeError (#5650, php-src-strict)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    array_replace_key([E::A => 'x'], [1 => 'y']);
    echo "accepted\n";
} catch (TypeError $e) {
    echo "TypeError\n";
    echo $e->getMessage(), "\n";
}
try {
    array_replace_key([1 => 'x'], [E::A => 'y']);
    echo "accepted2\n";
} catch (TypeError $e) {
    echo "TypeError2\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
Illegal offset type
TypeError2
Illegal offset type
