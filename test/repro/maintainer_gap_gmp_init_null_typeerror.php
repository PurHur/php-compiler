<?php

declare(strict_types=1);

$zero = gmp_init(null);
echo gmp_strval($zero), "\n";
echo gmp_cmp($zero, gmp_init(0)), "\n";

try {
    gmp_init([]);
} catch (\TypeError $e) {
    echo $e->getMessage(), "\n";
}
