<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

try {
    exit(E::A);
} catch (Error $e) {
    echo 'exit:', $e->getMessage(), "\n";
} catch (TypeError $e) {
    echo 'exit-TypeError:', $e->getMessage(), "\n";
}
