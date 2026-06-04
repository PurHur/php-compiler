<?php
// Compile-only (#5673, #5836): chr()/ord() must lower enum-case TypeError guards for AOT.
enum E: int { case A = 65; }
try {
    chr(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    ord(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
