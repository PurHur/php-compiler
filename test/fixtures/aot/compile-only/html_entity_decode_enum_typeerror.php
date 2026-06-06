<?php
declare(strict_types=1);
// Compile-only (#5899): html_entity_decode() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'a'; }
try {
    html_entity_decode(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
