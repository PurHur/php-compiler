<?php
declare(strict_types=1);

enum E: int {
    case A = 5;
}

try {
    unpack('i', pack('i', 1), E::A);
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
