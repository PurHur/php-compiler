<?php
try {
    var_dump(1 | "x");
    echo "or_survived\n";
} catch (TypeError $e) {
    echo "or:", $e->getMessage(), "\n";
}
try {
    var_dump(1 & "x");
    echo "and_survived\n";
} catch (TypeError $e) {
    echo "and:", $e->getMessage(), "\n";
}
try {
    var_dump(1 ^ "x");
    echo "xor_survived\n";
} catch (TypeError $e) {
    echo "xor:", $e->getMessage(), "\n";
}
try {
    var_dump("x" | 1);
    echo "strleft_survived\n";
} catch (TypeError $e) {
    echo "strleft:", $e->getMessage(), "\n";
}
$x = 1;
try {
    $x |= "x";
    echo "assign_survived\n";
} catch (TypeError $e) {
    echo "assign:caught\n";
}
// Numeric string and string⊙string byte-wise must keep working (#32407 / #32431).
var_dump(1 | "5");
var_dump("ab" | "cd");
echo "DONE\n";
