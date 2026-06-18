<?php
declare(strict_types=1);

enum E: int { case A = 1; }

try {
    [$x] = [E::A => 'v'];
    echo "x=", var_export($x, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
