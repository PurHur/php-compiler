<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

foreach (['sleep', 'usleep'] as $fn) {
    try {
        $fn(E::A);
        echo "{$fn}:uncaught\n";
    } catch (TypeError $e) {
        echo "{$fn}:TypeError:", $e->getMessage(), "\n";
    }
}
