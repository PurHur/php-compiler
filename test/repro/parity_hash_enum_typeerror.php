<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

try {
    hash('md5', E::A);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
