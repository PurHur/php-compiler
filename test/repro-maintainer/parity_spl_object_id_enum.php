<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
    case B = 2;
}

$a1 = spl_object_id(E::A);
$a2 = spl_object_id(E::A);
$b = spl_object_id(E::B);

if ($a1 !== $a2) {
    echo "fail unstable A: {$a1} vs {$a2}\n";
    exit(1);
}

if ($a1 === $b) {
    echo "fail A and B share id {$a1}\n";
    exit(1);
}

echo "ok {$a1} {$b}\n";
