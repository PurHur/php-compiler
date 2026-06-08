<?php
enum E: int { case A = 1; case B = 2; }
try {
    echo implode(',', [E::A, E::B]), "\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
