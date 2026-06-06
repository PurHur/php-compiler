<?php
declare(strict_types=1);
// Compile-only (#6863): stat()/is_link() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'x'; }

foreach (['stat', 'is_link'] as $fn) {
    try {
        $fn(E::A);
        echo "{$fn} uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
