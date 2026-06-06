<?php
// Compile-only (#6884): unitenum_exists() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'a'; }
try {
    unitenum_exists(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
