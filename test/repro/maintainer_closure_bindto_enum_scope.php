<?php

declare(strict_types=1);

enum E: int { case A = 1; }

$c = function (): E {
    return E::A;
};

$b = $c->bindTo(null, E::class);
$r = $b();

echo get_debug_type($r), "\n";
var_export($r === E::A);
echo "\n";
