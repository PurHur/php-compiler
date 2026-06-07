<?php
declare(strict_types=1);
// Compile-only (#5905): strstr-family builtins must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'hello'; }
try {
    strstr(E::A, 'l');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
