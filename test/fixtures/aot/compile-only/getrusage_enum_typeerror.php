<?php
// Compile-only (#6707): getrusage() must lower enum-case TypeError guards for AOT.
enum E: int { case A = 1; }
try {
    getrusage(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
