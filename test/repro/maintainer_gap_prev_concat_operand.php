<?php

declare(strict_types=1);

$a = [1, 2, 3];
next($a);

$concat = 'prev=' . var_export(prev($a), true);

if ('prev=1' !== $concat) {
    fwrite(STDERR, "concat prev: expected prev=1, got {$concat}\n");
    exit(1);
}

echo "ok\n";
