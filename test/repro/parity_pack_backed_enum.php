<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

try {
    $p = pack('i', E::A);
    echo 'len=', \strlen($p), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
