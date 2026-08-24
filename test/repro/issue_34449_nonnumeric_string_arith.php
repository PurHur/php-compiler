<?php
try {
    var_dump(1 * "x");
    echo "binop_survived\n";
} catch (TypeError $e) {
    echo "binop:", $e->getMessage(), "\n";
}
try {
    var_dump("x" * 1);
    echo "strleft_survived\n";
} catch (TypeError $e) {
    echo "strleft:", $e->getMessage(), "\n";
}
$x = 1;
try {
    $x *= "x";
    echo "mul_survived\n";
} catch (TypeError $e) {
    echo "mul:caught\n";
}
$y = 1;
try {
    $y += "x";
    echo "add_survived\n";
} catch (TypeError $e) {
    echo "add:caught\n";
}
class T { public int $p = 1; }
$o = new T;
try {
    $o->p *= "x";
    echo "typed_survived\n";
} catch (TypeError $e) {
    echo "typed:caught\n";
}
// Numeric string and leading-junk must keep working (#31967 / Zend warn+coerce).
var_dump("5" * 2);
var_dump(1 * "5x");
echo "DONE\n";
