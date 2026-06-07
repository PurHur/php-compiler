<?php
enum E: int { case A = 1; case B = 2; }
$v = [E::A, E::A, E::B];
try {
    var_dump(array_unique([E::A, E::A, E::B]));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
