--TEST--
Backed enum scalar arithmetic throws TypeError (issues #9568/#9618, Zend/zend_operators.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; case B = 2; }

try {
    var_export(E::A + 1);
    echo "no throw\n";
} catch (TypeError $e) {
    echo 'plus: TypeError:', $e->getMessage(), "\n";
}

try {
    var_export(E::A + E::B);
} catch (TypeError $e) {
    echo 'enum+: TypeError:', $e->getMessage(), "\n";
}

try {
    var_export(E::A - 1);
} catch (TypeError $e) {
    echo "minus: TypeError\n";
}

try {
    var_export(E::A * 2);
} catch (TypeError $e) {
    echo "mul: TypeError\n";
}

try {
    var_export(-E::A);
} catch (TypeError $e) {
    echo "unary: TypeError\n";
}

$a = E::A;
try {
    $a + 1;
} catch (TypeError $e) {
    echo "var: TypeError\n";
}
?>
--EXPECT--
plus: TypeError:Unsupported operand types: E + int
enum+: TypeError:Unsupported operand types: E + E
minus: TypeError
mul: TypeError
unary: TypeError
var: TypeError
