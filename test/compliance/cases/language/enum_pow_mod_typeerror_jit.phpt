--TEST--
Backed enum ** and % throw TypeError (JIT, #5794, zend_operators.c)
--FILE--
<?php
enum E: int { case A = 2; case B = 3; }

try {
    E::A ** E::B;
} catch (TypeError $e) {
    echo "pow enum: TypeError\n";
}

try {
    E::A % E::B;
} catch (TypeError $e) {
    echo "mod enum: TypeError\n";
}
?>
--EXPECT--
pow enum: TypeError
mod enum: TypeError
