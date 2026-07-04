<?php
enum E: int { case A = 1; }
enum Len: int { case Two = 2; }

$o = new stdClass();
try {
    var_export(property_exists($o, E::A));
    echo "\n";
} catch (TypeError $e) {
    echo "property_exists: ", $e->getMessage(), "\n";
}

try {
    array_chunk([1, 2, 3], Len::Two);
    echo "chunk: no error\n";
} catch (TypeError $e) {
    echo "chunk: ", $e->getMessage(), "\n";
}

try {
    array_pad([1], Len::Two, 0);
    echo "pad: no error\n";
} catch (TypeError $e) {
    echo "pad: ", $e->getMessage(), "\n";
}
