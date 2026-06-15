<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

try {
    var_export(pack('i', E::A));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
