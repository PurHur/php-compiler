<?php
// Compile-only (#5884): str_getcsv() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'a,b'; }
try {
    str_getcsv(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
