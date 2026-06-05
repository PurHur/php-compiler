<?php
declare(strict_types=1);
// Compile-only (#6148): sleep()/usleep() must lower enum-case TypeError guards for AOT.
enum E: int { case A = 1; }
try {
    var_export(sleep(E::A));
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    usleep(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
