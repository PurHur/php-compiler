<?php
declare(strict_types=1);
// Compile-only (#7185): quotemeta() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'a'; }
try {
    quotemeta(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
