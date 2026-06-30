<?php

declare(strict_types=1);

$a = [1, 2, 3];
next($a);

$concat = 'next=' . var_export(next($a), true);

if ('next=3' !== $concat) {
    fwrite(STDERR, "concat next: expected next=3, got {$concat}\n");
    exit(1);
}

echo "ok\n";
