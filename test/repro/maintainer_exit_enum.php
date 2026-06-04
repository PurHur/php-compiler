<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

try {
    exit(E::A);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

try {
    die(E::A);
} catch (Error $e) {
    echo 'die:', $e->getMessage(), "\n";
}

echo "ok\n";
