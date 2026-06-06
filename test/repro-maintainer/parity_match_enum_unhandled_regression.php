<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
    case B = 2;
}

try {
    match (E::A) {
        E::B => 'b',
    };
    echo "no throw\n";
} catch (UnhandledMatchError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
