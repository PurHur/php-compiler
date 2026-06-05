<?php
enum E: int { case A = 1; }

try {
    var_export(random_int(E::A, 5));
    echo "\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
