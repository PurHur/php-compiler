<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

try {
    unpack('C', E::A);
} catch (Throwable $e) {
    echo 'data: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    unpack(E::A, "\x01");
} catch (Throwable $e) {
    echo 'format: ', get_class($e), ': ', $e->getMessage(), "\n";
}

$r = unpack('C', "\x01");
echo 'ok: ', $r[1], "\n";
