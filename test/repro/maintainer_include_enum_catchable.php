<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

try {
    include E::A;
} catch (Error $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}
