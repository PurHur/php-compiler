<?php

declare(strict_types=1);

$a = [1, 2, 3];
next($a);

$concat = 'current=' . var_export(current($a), true);

if ('current=2' !== $concat) {
    fwrite(STDERR, "concat current: expected current=2, got {$concat}\n");
    exit(1);
}

echo "ok\n";
