<?php
enum E: int { case A = 1; }
try {
    $a = [];
    $a[E::A] = 1;
    var_export($a);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
