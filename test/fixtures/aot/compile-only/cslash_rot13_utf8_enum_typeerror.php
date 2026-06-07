<?php
// Compile-only (#5956): utf8_encode/str_rot13/addcslashes/stripcslashes enum-case TypeError guards for AOT.
enum E: string { case A = 'x'; }

foreach ([
    static fn () => utf8_encode(E::A),
    static fn () => str_rot13(E::A),
    static fn () => addcslashes(E::A, 'a'),
    static fn () => stripcslashes(E::A),
] as $fn) {
    try {
        $fn();
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
