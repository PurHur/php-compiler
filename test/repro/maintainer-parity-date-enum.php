<?php

declare(strict_types=1);

enum I: int
{
    case A = 1;
}

try {
    date('Y-m-d', I::A);
    echo "date ok\n";
} catch (Throwable $e) {
    echo 'date ', $e::class, ': ', $e->getMessage(), "\n";
}

try {
    gmdate('Y', I::A);
    echo "gmdate ok\n";
} catch (Throwable $e) {
    echo 'gmdate ', $e::class, ': ', $e->getMessage(), "\n";
}
