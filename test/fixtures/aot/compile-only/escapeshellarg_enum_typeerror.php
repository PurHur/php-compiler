<?php
declare(strict_types=1);
// Compile-only (#5870): escapeshellarg() must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'x'; }
try {
    var_export(escapeshellarg(E::A));
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
