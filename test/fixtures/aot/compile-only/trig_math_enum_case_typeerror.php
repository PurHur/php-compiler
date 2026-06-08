<?php
// Compile-only (#5883): trig/hyperbolic math builtins must lower enum-case TypeError guards for AOT.
enum E: int { case A = 1; }
foreach (['sin', 'cos', 'deg2rad', 'fmod'] as $fn) {
    try {
        $fn === 'fmod' ? $fn(E::A, 2.0) : $fn(E::A);
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
