<?php
// Compile-only (#5613): math builtins must lower enum-case TypeError guards for AOT.
enum E: int { case N = 5; }
try {
    abs(E::N);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    ceil(E::N);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    floor(E::N);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    round(E::N);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    sqrt(E::N);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
