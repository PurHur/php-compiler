<?php

declare(strict_types=1);

$direct = var_export('abc'[-1], true);
if ('\'c\'' !== $direct) {
    fwrite(STDERR, "fail: direct negative offset got {$direct}\n");
    exit(1);
}

$assigned = 'abc'[-1];
if ('c' !== $assigned) {
    fwrite(STDERR, "fail: assigned negative offset got {$assigned}\n");
    exit(1);
}

echo "ok\n";
