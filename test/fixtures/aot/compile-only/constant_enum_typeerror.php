<?php
// Compile-only (#7215): constant() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'x'; }
try {
    constant(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
