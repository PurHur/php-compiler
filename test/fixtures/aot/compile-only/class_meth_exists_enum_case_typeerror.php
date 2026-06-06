<?php
// Compile-only (#7068): class_meth_exists() enum-case TypeError guard for AOT.
enum E: string { case A = 'a'; }
try {
    class_meth_exists(E::A, 'cases');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
