<?php
// Compile-only (#5795): random_int() must lower enum-case TypeError guards for AOT.
enum E: int { case A = 1; }
try {
    random_int(E::A, 5);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    random_int(1, E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
