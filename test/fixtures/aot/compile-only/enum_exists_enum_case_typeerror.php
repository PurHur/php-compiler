<?php
// Compile-only (#6561): enum_exists() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'a'; }
try {
    enum_exists(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
