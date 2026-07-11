<?php

declare(strict_types=1);

enum Prefix
{
    case A;
}

try {
    extract(['a' => 2], EXTR_PREFIX_ALL, Prefix::A);
    echo "ok\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
