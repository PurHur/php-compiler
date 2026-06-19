<?php
declare(strict_types=1);

enum E: int { case A = 1; case B = 2; }

var_dump(match (1) {
    E::A => 'a',
    default => 'd',
});

switch (1) {
    case E::A:
        echo "switch matched\n";
        break;
    default:
        echo "switch default\n";
}
