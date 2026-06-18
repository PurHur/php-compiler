<?php
declare(strict_types=1);

enum E: int { case A = 1; }

try {
    echo E::A + 1, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    echo E::A - 1, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    echo E::A * 2, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
