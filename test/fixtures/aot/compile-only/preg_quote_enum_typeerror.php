<?php
declare(strict_types=1);
// Compile-only (#5999): preg_quote() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'x'; }
try {
    preg_quote(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
