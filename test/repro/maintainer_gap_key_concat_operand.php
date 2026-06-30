<?php

declare(strict_types=1);

$a = [1, 2, 3];
next($a);

$concat = 'key=' . var_export(key($a), true);

if ('key=1' !== $concat) {
    fwrite(STDERR, "concat key: expected key=1, got {$concat}\n");
    exit(1);
}

echo "ok\n";
