--TEST--
stdlib array_change_key_case() — enum case keys throw TypeError (#5571, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    array_change_key_case([E::A => 'v']);
    echo "accepted\n";
} catch (TypeError $e) {
    echo "TypeError\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
Illegal offset type
