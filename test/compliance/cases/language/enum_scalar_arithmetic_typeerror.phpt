--TEST--
Backed int enum + scalar arithmetic throws TypeError (#5790, zend_operators.c)
--FILE--
<?php
enum E: int { case A = 1; }

try {
    var_export(E::A + 1);
    echo "no throw\n";
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
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
    var_export(E::A / 2);
} catch (TypeError $e) {
    echo "div: TypeError\n";
}

$a = E::A;
try {
    $a + 1;
} catch (TypeError $e) {
    echo "var: TypeError\n";
}
?>
--EXPECT--
TypeError:Unsupported operand types: E + int
minus: TypeError
mul: TypeError
div: TypeError
var: TypeError
