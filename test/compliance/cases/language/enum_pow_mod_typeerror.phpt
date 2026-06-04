--TEST--
Backed enum ** and % throw TypeError (#5794, zend_operators.c)
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

$a = E::A;
$b = E::B;

try {
    $a ** $b;
} catch (TypeError $e) {
    echo "pow var: TypeError\n";
}

try {
    $a % $b;
} catch (TypeError $e) {
    echo "mod var: TypeError\n";
}

try {
    E::A ** 2;
} catch (TypeError $e) {
    echo 'pow scalar: TypeError:', $e->getMessage(), "\n";
}
?>
--EXPECT--
pow enum: TypeError
mod enum: TypeError
pow var: TypeError
mod var: TypeError
pow scalar: TypeError:Unsupported operand types: E ** int
